<?php

namespace App\Services;

use App\Jobs\EnforceVirtualEsimDurationJob;
use App\Jobs\EnforceVirtualEsimQuotaJob;
use App\Models\Simcard;
use App\Models\SimcardTopupSession;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VirtualEsimQuotaService
{
    public const STRATEGY = 'quota_capped_provider_plan_v1';

    private const CHECK_INTERVAL_SECONDS = 300;

    private const PROVIDER_MIN_INTERVAL_SECONDS = 0.15;

    public function __construct(
        private readonly EsimProvider $provider,
        private readonly EsimCryptoService $crypto,
    ) {}

    public function isQuotaCapped(Simcard|array|null $source): bool
    {
        $recipe = $source instanceof Simcard ? $source->virtual_fulfillment_recipe : $source;

        return is_array($recipe)
            && (string) ($recipe['strategy'] ?? '') === self::STRATEGY;
    }

    public function isDurationCapped(Simcard|array|null $source): bool
    {
        $recipe = $source instanceof Simcard ? $source->virtual_fulfillment_recipe : $source;

        return is_array($recipe)
            && filter_var(data_get($recipe, 'duration_entitlement.enforced', false), FILTER_VALIDATE_BOOLEAN)
            && $this->positiveInt(data_get($recipe, 'duration_entitlement.target_duration_days')) !== null;
    }

    /**
     * Return customer-visible usage for quota-capped virtual plans while preserving
     * raw provider counters on the Simcard model for internal enforcement.
     *
     * @return array{total_bytes:?int,used_bytes:?int,remaining_bytes:?int}
     */
    public function effectiveUsage(
        Simcard $simcard,
        ?int $providerTotalBytes = null,
        ?int $providerUsedBytes = null,
        ?int $providerRemainingBytes = null,
    ): array {
        if (! $this->isQuotaCapped($simcard)) {
            return [
                'total_bytes' => $providerTotalBytes,
                'used_bytes' => $providerUsedBytes,
                'remaining_bytes' => $providerRemainingBytes,
            ];
        }

        $recipe = (array) $simcard->virtual_fulfillment_recipe;
        $entitlement = $this->positiveInt(data_get($recipe, 'quota.entitlement_bytes'))
            ?? $this->positiveInt($recipe['target_data_bytes'] ?? null);

        if ($entitlement === null) {
            return [
                'total_bytes' => $providerTotalBytes,
                'used_bytes' => $providerUsedBytes,
                'remaining_bytes' => $providerRemainingBytes,
            ];
        }

        $used = $providerUsedBytes !== null ? max(0, $providerUsedBytes) : null;

        return [
            'total_bytes' => $entitlement,
            'used_bytes' => $used !== null ? min($used, $entitlement) : null,
            'remaining_bytes' => $used !== null ? max(0, $entitlement - $used) : null,
        ];
    }

    /** @return array{0:?int,1:?int,2:?int} */
    public function effectiveUsageTuple(Simcard $simcard): array
    {
        $effective = $this->effectiveUsage(
            $simcard,
            $this->positiveInt($simcard->total_volume),
            $this->nonNegativeInt($simcard->order_usage),
            $this->nonNegativeInt($simcard->remaining_volume),
        );

        return [
            $effective['total_bytes'],
            $effective['remaining_bytes'],
            $effective['used_bytes'],
        ];
    }

    public function effectiveExpiresAt(Simcard $simcard): ?Carbon
    {
        if ($this->isDurationCapped($simcard)) {
            return $this->parseDate(data_get(
                $simcard->virtual_fulfillment_recipe,
                'duration_entitlement.customer_expires_at',
            ));
        }

        return $simcard->expires_at;
    }

    public function effectiveRemainingValidityDays(Simcard $simcard): ?int
    {
        if (! $this->isDurationCapped($simcard)) {
            return is_numeric($simcard->remaining_validity) ? (int) $simcard->remaining_validity : null;
        }

        $expiry = $this->effectiveExpiresAt($simcard);
        if ($expiry !== null) {
            return max(0, (int) ceil(now()->diffInSeconds($expiry, false) / 86400));
        }

        return $this->positiveInt(data_get(
            $simcard->virtual_fulfillment_recipe,
            'duration_entitlement.entitled_duration_days',
        )) ?? $this->positiveInt(data_get(
            $simcard->virtual_fulfillment_recipe,
            'duration_entitlement.target_duration_days',
        ));
    }

    public function allowsPaidTopupWhileSuspended(Simcard $simcard): bool
    {
        if (! $this->isQuotaCapped($simcard) && ! $this->isDurationCapped($simcard)) {
            return false;
        }

        $quotaState = strtoupper(trim((string) data_get($simcard->virtual_fulfillment_recipe, 'quota.state', '')));
        $durationState = strtoupper(trim((string) data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.state', '')));

        return in_array($quotaState, ['SUSPENDED', 'SUSPEND_QUEUED', 'SUSPENDING', 'SUSPEND_RETRYING', 'RESTORE_REQUIRED'], true)
            || in_array($durationState, ['SUSPENDED', 'SUSPEND_QUEUED', 'SUSPENDING', 'SUSPEND_RETRYING', 'RESTORE_REQUIRED'], true)
            || strtoupper(trim((string) $simcard->esim_status)) === 'SUSPENDED';
    }

    /**
     * A customer-paid top-up must restore a quota-suspended profile before the
     * provider top-up write is attempted. This does not change the entitlement yet;
     * entitlement is extended only after the provider confirms the paid top-up.
     */
    public function restoreForPaidTopup(Simcard $simcard): void
    {
        if (! $this->allowsPaidTopupWhileSuspended($simcard)) {
            return;
        }

        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            throw new RuntimeException('Virtual eSIM ICCID is not ready for reactivation.', 503);
        }

        $account = $this->preferredProviderAccount($simcard);
        $response = $this->provider->unsuspendEsim($iccid, $account);

        if (! $this->providerMutationSucceeded($response)) {
            $provider = $this->queryProviderSim($iccid, $account);
            if (strtoupper(trim((string) ($provider['esimStatus'] ?? ''))) !== 'IN_USE') {
                throw new RuntimeException('Provider could not reactivate the virtual eSIM for the paid top-up.', 503);
            }
        }

        DB::transaction(function () use ($simcard): void {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || (! $this->isQuotaCapped($locked) && ! $this->isDurationCapped($locked))) {
                return;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            if ($this->isQuotaCapped($recipe)) {
                $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
                $quota['state'] = 'MONITORING';
                $quota['unsuspended_at'] = now()->toIso8601String();
                unset($quota['last_suspend_error'], $quota['last_suspend_error_at']);
                $recipe['quota'] = $quota;
                $recipe['status'] = 'QUOTA_MONITORING';
            }
            if ($this->isDurationCapped($recipe)) {
                $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
                $duration['state'] = $locked->activated_at === null ? 'WAITING_FOR_ACTIVATION' : 'MONITORING';
                $duration['unsuspended_at'] = now()->toIso8601String();
                unset($duration['last_suspend_error'], $duration['last_suspend_error_at']);
                $recipe['duration_entitlement'] = $duration;
            }
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->esim_status = 'IN_USE';
            $locked->state = 'active';
            $locked->save();
        });
    }

    /**
     * Extend the customer entitlement exactly once after a real customer-paid top-up
     * has been confirmed by the provider. Included virtual-plan top-ups never call this.
     */
    public function extendEntitlementForPaidTopup(Simcard $simcard, SimcardTopupSession $session): void
    {
        if (! $this->isQuotaCapped($simcard) || (int) ($session->data_bytes ?? 0) <= 0) {
            return;
        }

        $meta = is_array($session->meta) ? $session->meta : [];
        if ((string) ($meta['source'] ?? '') === 'virtual_plan_fulfillment') {
            return;
        }

        $result = DB::transaction(function () use ($simcard, $session): array {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isQuotaCapped($locked)) {
                return ['applied' => false, 'needs_restore' => false];
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $applied = is_array($quota['paid_topup_session_ids'] ?? null)
                ? array_values(array_map('strval', $quota['paid_topup_session_ids']))
                : [];

            if (in_array((string) $session->id, $applied, true)) {
                return ['applied' => false, 'needs_restore' => false];
            }

            $stateBefore = strtoupper(trim((string) ($quota['state'] ?? 'MONITORING')));
            $needsRestore = in_array(
                $stateBefore,
                ['SUSPENDED', 'SUSPENDING', 'SUSPEND_RETRYING', 'RESTORE_REQUIRED'],
                true,
            ) || strtoupper(trim((string) $locked->esim_status)) === 'SUSPENDED';

            $currentEntitlement = $this->positiveInt($quota['entitlement_bytes'] ?? null)
                ?? $this->positiveInt($recipe['target_data_bytes'] ?? null)
                ?? 0;
            $addedBytes = max(0, (int) $session->data_bytes);

            $quota['entitlement_bytes'] = $currentEntitlement + $addedBytes;
            $quota['customer_visible_total_bytes'] = $quota['entitlement_bytes'];
            $quota['provider_allowance_bytes'] = max(
                (int) ($quota['provider_allowance_bytes'] ?? 0) + $addedBytes,
                $quota['entitlement_bytes'],
            );
            $applied[] = (string) $session->id;
            $quota['paid_topup_session_ids'] = array_values(array_unique($applied));
            $quota['last_entitlement_topup_at'] = now()->toIso8601String();
            $quota['state'] = $needsRestore ? 'RESTORE_REQUIRED' : 'MONITORING';
            $recipe['quota'] = $quota;
            $recipe['status'] = $needsRestore ? 'QUOTA_RESTORE_REQUIRED' : 'QUOTA_MONITORING';
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();

            return [
                'applied' => true,
                'needs_restore' => $needsRestore,
                'entitlement' => (int) $quota['entitlement_bytes'],
                'usage' => $this->nonNegativeInt($locked->order_usage) ?? 0,
            ];
        });

        if (($result['needs_restore'] ?? false) === true) {
            $fresh = Simcard::query()->whereKey($simcard->id)->first();
            if ($fresh !== null) {
                $this->restoreAfterQuotaExtension(
                    $fresh,
                    (int) ($result['usage'] ?? 0),
                    (int) ($result['entitlement'] ?? 0),
                );
            }
        }
    }

    /**
     * A customer-paid top-up extends an enforced virtual-plan deadline by the
     * provider package's own validity. Before activation the extra days remain
     * pending, so the customer's validity still starts on first use.
     */
    public function extendDurationEntitlementForPaidTopup(Simcard $simcard, SimcardTopupSession $session): void
    {
        $addedDays = (int) ($session->duration_days ?? 0);
        if (! $this->isDurationCapped($simcard) || $addedDays <= 0) {
            return;
        }

        $meta = is_array($session->meta) ? $session->meta : [];
        if ((string) ($meta['source'] ?? '') === 'virtual_plan_fulfillment') {
            return;
        }

        DB::transaction(function () use ($simcard, $session, $addedDays): void {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isDurationCapped($locked)) {
                return;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
            $applied = is_array($duration['paid_topup_session_ids'] ?? null)
                ? array_values(array_map('strval', $duration['paid_topup_session_ids']))
                : [];

            if (in_array((string) $session->id, $applied, true)) {
                return;
            }

            $targetDays = $this->positiveInt($duration['target_duration_days'] ?? null) ?? 0;
            $entitledDays = $this->positiveInt($duration['entitled_duration_days'] ?? null) ?? $targetDays;
            $duration['entitled_duration_days'] = $entitledDays + $addedDays;

            if ($locked->activated_at !== null) {
                $currentExpiry = $this->parseDate($duration['customer_expires_at'] ?? null)
                    ?? $locked->activated_at->copy()->addDays($entitledDays);
                $duration['customer_expires_at'] = ($currentExpiry->isFuture() ? $currentExpiry : now())
                    ->copy()
                    ->addDays($addedDays)
                    ->toIso8601String();
                $duration['state'] = 'MONITORING';
            } else {
                $duration['customer_expires_at'] = null;
                $duration['state'] = 'WAITING_FOR_ACTIVATION';
            }

            $applied[] = (string) $session->id;
            $duration['paid_topup_session_ids'] = array_values(array_unique($applied));
            $duration['last_entitlement_topup_at'] = now()->toIso8601String();
            unset($duration['restore_required_at'], $duration['last_suspend_error'], $duration['last_suspend_error_at']);
            $recipe['duration_entitlement'] = $duration;
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();
        });
    }

    /**
     * Evaluate already-stored provider usage and queue a suspend write when the
     * advertised entitlement has been reached.
     *
     * @return array<string,mixed>
     */
    public function processStoredUsage(Simcard|string $source): array
    {
        $simcard = $source instanceof Simcard
            ? $source->fresh()
            : Simcard::query()->whereKey($source)->first();

        if ($simcard === null || ! $this->isQuotaCapped($simcard)) {
            return ['status' => 'skipped', 'reason' => 'not_quota_capped'];
        }

        $recipe = (array) $simcard->virtual_fulfillment_recipe;
        $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
        $entitlement = $this->positiveInt($quota['entitlement_bytes'] ?? null)
            ?? $this->positiveInt($recipe['target_data_bytes'] ?? null);
        $usage = $this->nonNegativeInt($simcard->order_usage);

        if ($entitlement === null || $usage === null) {
            return ['status' => 'skipped', 'reason' => 'usage_not_ready'];
        }

        $state = strtoupper(trim((string) ($quota['state'] ?? 'MONITORING')));
        if ($state === 'SUSPENDED') {
            return ['status' => 'suspended', 'usage_bytes' => $usage, 'entitlement_bytes' => $entitlement];
        }

        $providerStatus = strtoupper(trim((string) $simcard->esim_status));
        if (in_array($providerStatus, ['USED_UP', 'EXPIRED', 'CANCELLED', 'CANCELED', 'REVOKED'], true)) {
            return [
                'status' => 'skipped',
                'reason' => 'provider_service_already_unavailable',
                'usage_bytes' => $usage,
                'entitlement_bytes' => $entitlement,
            ];
        }

        DB::transaction(function () use ($simcard, $usage, $entitlement): void {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isQuotaCapped($locked)) {
                return;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $quota['last_observed_usage_bytes'] = $usage;
            $quota['customer_visible_remaining_bytes'] = max(0, $entitlement - $usage);
            $recipe['quota'] = $quota;
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();
        });

        if ($usage < $entitlement) {
            return [
                'status' => 'monitoring',
                'usage_bytes' => $usage,
                'entitlement_bytes' => $entitlement,
                'remaining_bytes' => max(0, $entitlement - $usage),
            ];
        }

        $queued = DB::transaction(function () use ($simcard): bool {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isQuotaCapped($locked)) {
                return false;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $state = strtoupper(trim((string) ($quota['state'] ?? 'MONITORING')));

            if (in_array($state, ['SUSPENDED', 'SUSPEND_QUEUED', 'SUSPENDING'], true)) {
                return false;
            }

            $quota['state'] = 'SUSPEND_QUEUED';
            $quota['suspend_queued_at'] = now()->toIso8601String();
            $recipe['quota'] = $quota;
            $recipe['status'] = 'QUOTA_SUSPEND_QUEUED';
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();

            return true;
        });

        if ($queued) {
            EnforceVirtualEsimQuotaJob::dispatch((string) $simcard->id);
        }

        return [
            'status' => $queued ? 'suspend_queued' : 'suspend_already_queued',
            'usage_bytes' => $usage,
            'entitlement_bytes' => $entitlement,
        ];
    }

    /** @return array<string,int> */
    public function processPending(int $limit = 100, ?string $onlySimcardId = null, bool $force = false): array
    {
        $summary = [
            'processed' => 0,
            'refreshed' => 0,
            'monitoring' => 0,
            'suspend_queued' => 0,
            'suspended' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $query = Simcard::query()->whereNotNull('virtual_fulfillment_recipe');
        $onlySimcardId = trim((string) $onlySimcardId);
        if ($onlySimcardId !== '') {
            $query->whereKey($onlySimcardId);
        }

        // The current virtual catalogue is small enough to sort due checks in PHP.
        // This keeps the feature additive and avoids adding scheduling columns to the
        // core simcards table solely for the quota fallback.
        $candidates = $query->limit(5000)->get()
            ->filter(fn (Simcard $simcard): bool => $this->isQuotaCapped($simcard))
            ->filter(function (Simcard $simcard) use ($force): bool {
                $state = strtoupper(trim((string) data_get($simcard->virtual_fulfillment_recipe, 'quota.state', 'MONITORING')));
                if ($state === 'SUSPENDED') {
                    return false;
                }

                if ($force) {
                    return true;
                }

                $last = data_get($simcard->virtual_fulfillment_recipe, 'quota.last_checked_at');
                if (! is_string($last) || trim($last) === '') {
                    return true;
                }

                try {
                    return Carbon::parse($last)->diffInSeconds(now(), true) >= self::CHECK_INTERVAL_SECONDS;
                } catch (Throwable) {
                    return true;
                }
            })
            ->sortBy(function (Simcard $simcard): string {
                return (string) data_get($simcard->virtual_fulfillment_recipe, 'quota.last_checked_at', '');
            })
            ->take(max(1, min($limit, 500)))
            ->values();

        foreach ($candidates as $simcard) {
            $summary['processed']++;

            try {
                $refresh = $this->refreshUsageFromProvider($simcard);
                if (($refresh['status'] ?? '') === 'refreshed') {
                    $summary['refreshed']++;
                } else {
                    $summary['skipped']++;

                    continue;
                }

                $result = $this->processStoredUsage((string) $simcard->id);
                $status = (string) ($result['status'] ?? 'skipped');

                if (isset($summary[$status])) {
                    $summary[$status]++;
                } elseif (str_starts_with($status, 'suspend_')) {
                    $summary['suspend_queued']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
                Log::warning('Virtual eSIM quota usage refresh failed.', [
                    'simcard_id' => (string) $simcard->id,
                    'exception' => class_basename($exception),
                ]);
            }
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    public function enforceSuspend(string $simcardId): array
    {
        $simcard = Simcard::query()->whereKey($simcardId)->first();
        if ($simcard === null || ! $this->isQuotaCapped($simcard)) {
            return ['status' => 'skipped'];
        }

        $recipe = (array) $simcard->virtual_fulfillment_recipe;
        $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
        $entitlement = $this->positiveInt($quota['entitlement_bytes'] ?? null)
            ?? $this->positiveInt($recipe['target_data_bytes'] ?? null);
        $usage = $this->nonNegativeInt($simcard->order_usage);
        $state = strtoupper(trim((string) ($quota['state'] ?? 'MONITORING')));

        if ($entitlement === null || $usage === null) {
            return ['status' => 'skipped', 'reason' => 'quota_not_ready'];
        }

        if ($usage < $entitlement) {
            // A paid/Auto Top-Up can extend entitlement while a previously queued
            // suspend job is still waiting or retrying. If a suspension may already
            // have reached the provider, restore service instead of silently skipping.
            if (in_array($state, ['SUSPENDED', 'SUSPENDING', 'SUSPEND_RETRYING'], true)) {
                return $this->restoreAfterQuotaExtension($simcard, $usage, $entitlement);
            }

            return ['status' => 'skipped', 'reason' => 'quota_not_reached'];
        }

        if ($state === 'SUSPENDED') {
            return ['status' => 'suspended', 'idempotent' => true];
        }

        DB::transaction(function () use ($simcard): void {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isQuotaCapped($locked)) {
                return;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $quota['state'] = 'SUSPENDING';
            $quota['last_suspend_attempt_at'] = now()->toIso8601String();
            $recipe['quota'] = $quota;
            $recipe['status'] = 'QUOTA_SUSPENDING';
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();
        });

        $simcard->refresh();
        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            throw new RuntimeException('Quota-capped eSIM ICCID is not available for suspension.', 503);
        }

        $account = $this->preferredProviderAccount($simcard);
        $response = $this->provider->suspendEsim($iccid, $account);

        if (! $this->providerMutationSucceeded($response)) {
            $providerSim = $this->queryProviderSim($iccid, $account);
            if (strtoupper(trim((string) ($providerSim['esimStatus'] ?? ''))) !== 'SUSPENDED') {
                throw new RuntimeException('Provider did not confirm virtual eSIM quota suspension.', 503);
            }
        }

        // Re-read under a lock after the provider mutation. A paid top-up may have
        // extended entitlement while this job was in flight. Never finalize a stale
        // suspension against the old quota.
        $final = DB::transaction(function () use ($simcard): array {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isQuotaCapped($locked)) {
                return ['action' => 'skip'];
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $latestEntitlement = $this->positiveInt($quota['entitlement_bytes'] ?? null)
                ?? $this->positiveInt($recipe['target_data_bytes'] ?? null);
            $latestUsage = $this->nonNegativeInt($locked->order_usage);

            if ($latestEntitlement !== null && $latestUsage !== null && $latestUsage < $latestEntitlement) {
                $quota['state'] = 'RESTORE_REQUIRED';
                $quota['restore_required_at'] = now()->toIso8601String();
                $recipe['quota'] = $quota;
                $recipe['status'] = 'QUOTA_RESTORE_REQUIRED';
                $locked->virtual_fulfillment_recipe = $recipe;
                $locked->save();

                return [
                    'action' => 'restore',
                    'usage' => $latestUsage,
                    'entitlement' => $latestEntitlement,
                ];
            }

            $quota['state'] = 'SUSPENDED';
            $quota['suspended_at'] = now()->toIso8601String();
            $quota['suspended_at_usage_bytes'] = $latestUsage;
            $quota['customer_visible_remaining_bytes'] = 0;
            unset($quota['last_suspend_error'], $quota['last_suspend_error_at']);
            $recipe['quota'] = $quota;
            $recipe['status'] = 'QUOTA_SUSPENDED';
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->esim_status = 'SUSPENDED';
            $locked->state = 'suspended';
            $locked->save();

            return [
                'action' => 'suspended',
                'usage' => $latestUsage,
                'entitlement' => $latestEntitlement,
            ];
        });

        if (($final['action'] ?? '') === 'restore') {
            $latest = Simcard::query()->whereKey($simcard->id)->first();
            if ($latest === null) {
                throw new RuntimeException('Quota-capped eSIM disappeared during restore.', 503);
            }

            return $this->restoreAfterQuotaExtension(
                $latest,
                (int) ($final['usage'] ?? 0),
                (int) ($final['entitlement'] ?? 0),
            );
        }

        return ['status' => ($final['action'] ?? '') === 'suspended' ? 'suspended' : 'skipped'];
    }

    /** @return array<string,mixed> */
    private function restoreAfterQuotaExtension(Simcard $simcard, int $usage, int $entitlement): array
    {
        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            throw new RuntimeException('Quota-capped eSIM ICCID is not available for quota restore.', 503);
        }

        $account = $this->preferredProviderAccount($simcard);
        $response = $this->provider->unsuspendEsim($iccid, $account);

        if (! $this->providerMutationSucceeded($response)) {
            $providerSim = $this->queryProviderSim($iccid, $account);
            if (strtoupper(trim((string) ($providerSim['esimStatus'] ?? ''))) !== 'IN_USE') {
                throw new RuntimeException('Provider did not confirm quota-capped eSIM restore after entitlement extension.', 503);
            }
        }

        DB::transaction(function () use ($simcard, $usage, $entitlement): void {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isQuotaCapped($locked)) {
                return;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $latestEntitlement = $this->positiveInt($quota['entitlement_bytes'] ?? null) ?? $entitlement;
            $latestUsage = $this->nonNegativeInt($locked->order_usage) ?? $usage;

            // If usage raced past even the newly extended entitlement, leave the
            // profile eligible for the normal quota processor to queue suspension again.
            $quota['state'] = 'MONITORING';
            $quota['restored_after_entitlement_extension_at'] = now()->toIso8601String();
            $quota['customer_visible_remaining_bytes'] = max(0, $latestEntitlement - $latestUsage);
            unset(
                $quota['restore_required_at'],
                $quota['last_suspend_error'],
                $quota['last_suspend_error_at']
            );
            $recipe['quota'] = $quota;
            $recipe['status'] = 'QUOTA_MONITORING';
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->esim_status = 'IN_USE';
            $locked->state = 'active';
            $locked->save();
        });

        // Re-evaluate once after restore in case the latest provider usage already
        // crossed the newly extended entitlement as well.
        $result = $this->processStoredUsage((string) $simcard->id);

        return [
            'status' => 'restored_after_entitlement_extension',
            'quota_result' => $result,
        ];
    }

    /** @return array<string,mixed> */
    public function processDurationStored(Simcard|string $source): array
    {
        $simcard = $source instanceof Simcard
            ? $source->fresh()
            : Simcard::query()->whereKey($source)->first();

        if ($simcard === null || ! $this->isDurationCapped($simcard)) {
            return ['status' => 'skipped', 'reason' => 'not_duration_capped'];
        }

        $recipe = (array) $simcard->virtual_fulfillment_recipe;
        $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
        $state = strtoupper(trim((string) ($duration['state'] ?? 'WAITING_FOR_ACTIVATION')));

        if ($state === 'SUSPENDED') {
            return ['status' => 'suspended'];
        }

        $providerStatus = strtoupper(trim((string) $simcard->esim_status));
        if (in_array($providerStatus, ['USED_UP', 'EXPIRED', 'CANCELLED', 'CANCELED', 'REVOKED'], true)) {
            return ['status' => 'skipped', 'reason' => 'provider_service_already_unavailable'];
        }

        if ($simcard->activated_at === null) {
            $this->recordDurationCheck($simcard, 'WAITING_FOR_ACTIVATION');

            return ['status' => 'waiting'];
        }

        $expiry = $this->parseDate($duration['customer_expires_at'] ?? null);
        if ($expiry === null) {
            $entitledDays = $this->positiveInt($duration['entitled_duration_days'] ?? null)
                ?? $this->positiveInt($duration['target_duration_days'] ?? null);
            if ($entitledDays === null) {
                return ['status' => 'skipped', 'reason' => 'duration_not_ready'];
            }

            $expiry = $simcard->activated_at->copy()->addDays($entitledDays);
            DB::transaction(function () use ($simcard, $expiry): void {
                $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
                if ($locked === null || ! $this->isDurationCapped($locked)) {
                    return;
                }

                $recipe = (array) $locked->virtual_fulfillment_recipe;
                $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
                if ($this->parseDate($duration['customer_expires_at'] ?? null) === null) {
                    $duration['customer_expires_at'] = $expiry->toIso8601String();
                }
                $duration['state'] = 'MONITORING';
                $duration['last_checked_at'] = now()->toIso8601String();
                $recipe['duration_entitlement'] = $duration;
                $locked->virtual_fulfillment_recipe = $recipe;
                $locked->save();
            });
        } else {
            $this->recordDurationCheck($simcard, $state === 'WAITING_FOR_ACTIVATION' ? 'MONITORING' : $state);
        }

        if ($expiry->isFuture()) {
            return ['status' => 'monitoring', 'customer_expires_at' => $expiry->toIso8601String()];
        }

        $queued = DB::transaction(function () use ($simcard): bool {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isDurationCapped($locked)) {
                return false;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
            $latestExpiry = $this->parseDate($duration['customer_expires_at'] ?? null);
            $latestState = strtoupper(trim((string) ($duration['state'] ?? 'MONITORING')));

            if ($latestExpiry === null || $latestExpiry->isFuture()
                || in_array($latestState, ['SUSPENDED', 'SUSPEND_QUEUED', 'SUSPENDING'], true)) {
                return false;
            }

            $duration['state'] = 'SUSPEND_QUEUED';
            $duration['suspend_queued_at'] = now()->toIso8601String();
            $recipe['duration_entitlement'] = $duration;
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();

            return true;
        });

        if ($queued) {
            EnforceVirtualEsimDurationJob::dispatch((string) $simcard->id);
        }

        return [
            'status' => $queued ? 'suspend_queued' : 'suspend_already_queued',
            'customer_expires_at' => $expiry->toIso8601String(),
        ];
    }

    /** @return array<string,int> */
    public function processDurationPending(int $limit = 500, ?string $onlySimcardId = null): array
    {
        $summary = [
            'processed' => 0,
            'monitoring' => 0,
            'waiting' => 0,
            'suspend_queued' => 0,
            'suspended' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $query = Simcard::query()->whereNotNull('virtual_fulfillment_recipe');
        $onlySimcardId = trim((string) $onlySimcardId);
        if ($onlySimcardId !== '') {
            $query->whereKey($onlySimcardId);
        }

        $candidates = $query->limit(5000)->get()
            ->filter(fn (Simcard $simcard): bool => $this->isDurationCapped($simcard))
            ->filter(fn (Simcard $simcard): bool => strtoupper(trim((string) data_get(
                $simcard->virtual_fulfillment_recipe,
                'duration_entitlement.state',
                'WAITING_FOR_ACTIVATION',
            ))) !== 'SUSPENDED')
            ->sortBy(fn (Simcard $simcard): string => (string) data_get(
                $simcard->virtual_fulfillment_recipe,
                'duration_entitlement.last_checked_at',
                '',
            ))
            ->take(max(1, min($limit, 1000)))
            ->values();

        foreach ($candidates as $simcard) {
            $summary['processed']++;
            try {
                $result = $this->processDurationStored((string) $simcard->id);
                $status = (string) ($result['status'] ?? 'skipped');
                if (isset($summary[$status])) {
                    $summary[$status]++;
                } elseif (str_starts_with($status, 'suspend_')) {
                    $summary['suspend_queued']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
                Log::warning('Virtual eSIM duration enforcement check failed.', [
                    'simcard_id' => (string) $simcard->id,
                    'exception' => class_basename($exception),
                ]);
            }
        }

        return $summary;
    }

    /** @return array<string,mixed> */
    public function enforceDurationSuspend(string $simcardId): array
    {
        $simcard = Simcard::query()->whereKey($simcardId)->first();
        if ($simcard === null || ! $this->isDurationCapped($simcard)) {
            return ['status' => 'skipped'];
        }

        $recipe = (array) $simcard->virtual_fulfillment_recipe;
        $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
        $expiry = $this->parseDate($duration['customer_expires_at'] ?? null);
        $state = strtoupper(trim((string) ($duration['state'] ?? 'MONITORING')));

        if ($expiry === null) {
            return ['status' => 'skipped', 'reason' => 'duration_not_ready'];
        }
        if ($expiry->isFuture()) {
            if (in_array($state, ['SUSPENDED', 'SUSPENDING', 'SUSPEND_RETRYING', 'RESTORE_REQUIRED'], true)) {
                $this->restoreForPaidTopup($simcard);

                return ['status' => 'restored_after_duration_extension'];
            }

            return ['status' => 'skipped', 'reason' => 'duration_not_reached'];
        }
        if ($state === 'SUSPENDED') {
            return ['status' => 'suspended', 'idempotent' => true];
        }

        $shouldSuspend = DB::transaction(function () use ($simcard): bool {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isDurationCapped($locked)) {
                return false;
            }
            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
            $latestExpiry = $this->parseDate($duration['customer_expires_at'] ?? null);
            if ($latestExpiry === null || $latestExpiry->isFuture()) {
                $duration['state'] = $locked->activated_at === null ? 'WAITING_FOR_ACTIVATION' : 'MONITORING';
                $recipe['duration_entitlement'] = $duration;
                $locked->virtual_fulfillment_recipe = $recipe;
                $locked->save();

                return false;
            }
            $duration['state'] = 'SUSPENDING';
            $duration['last_suspend_attempt_at'] = now()->toIso8601String();
            $recipe['duration_entitlement'] = $duration;
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();

            return true;
        });

        if (! $shouldSuspend) {
            return ['status' => 'skipped', 'reason' => 'duration_extended_before_suspend'];
        }

        $simcard->refresh();
        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            throw new RuntimeException('Duration-capped eSIM ICCID is not available for suspension.', 503);
        }

        $account = $this->preferredProviderAccount($simcard);
        $response = $this->provider->suspendEsim($iccid, $account);
        if (! $this->providerMutationSucceeded($response)) {
            $providerSim = $this->queryProviderSim($iccid, $account);
            if (strtoupper(trim((string) ($providerSim['esimStatus'] ?? ''))) !== 'SUSPENDED') {
                throw new RuntimeException('Provider did not confirm virtual eSIM duration suspension.', 503);
            }
        }

        $final = DB::transaction(function () use ($simcard): string {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isDurationCapped($locked)) {
                return 'skip';
            }
            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
            $latestExpiry = $this->parseDate($duration['customer_expires_at'] ?? null);

            if ($latestExpiry !== null && $latestExpiry->isFuture()) {
                $duration['state'] = 'RESTORE_REQUIRED';
                $duration['restore_required_at'] = now()->toIso8601String();
                $recipe['duration_entitlement'] = $duration;
                $locked->virtual_fulfillment_recipe = $recipe;
                $locked->save();

                return 'restore';
            }

            $duration['state'] = 'SUSPENDED';
            $duration['suspended_at'] = now()->toIso8601String();
            unset($duration['last_suspend_error'], $duration['last_suspend_error_at']);
            $recipe['duration_entitlement'] = $duration;
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->esim_status = 'SUSPENDED';
            $locked->state = 'suspended';
            $locked->save();

            return 'suspended';
        });

        if ($final === 'restore') {
            $fresh = Simcard::query()->whereKey($simcard->id)->first();
            if ($fresh !== null) {
                $this->restoreForPaidTopup($fresh);
            }

            return ['status' => 'restored_after_duration_extension'];
        }

        return ['status' => $final === 'suspended' ? 'suspended' : 'skipped'];
    }

    public function markDurationSuspendRetrying(string $simcardId, string $reason): void
    {
        DB::transaction(function () use ($simcardId, $reason): void {
            $simcard = Simcard::query()->whereKey($simcardId)->lockForUpdate()->first();
            if ($simcard === null || ! $this->isDurationCapped($simcard)) {
                return;
            }

            $recipe = (array) $simcard->virtual_fulfillment_recipe;
            $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
            if (strtoupper(trim((string) ($duration['state'] ?? ''))) === 'SUSPENDED') {
                return;
            }
            $duration['state'] = 'SUSPEND_RETRYING';
            $duration['last_suspend_error'] = mb_substr($reason, 0, 500);
            $duration['last_suspend_error_at'] = now()->toIso8601String();
            $recipe['duration_entitlement'] = $duration;
            $simcard->virtual_fulfillment_recipe = $recipe;
            $simcard->save();
        });
    }

    private function recordDurationCheck(Simcard $simcard, string $state): void
    {
        DB::transaction(function () use ($simcard, $state): void {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isDurationCapped($locked)) {
                return;
            }

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $duration = is_array($recipe['duration_entitlement'] ?? null) ? $recipe['duration_entitlement'] : [];
            $currentState = strtoupper(trim((string) ($duration['state'] ?? '')));
            if (! in_array($currentState, ['SUSPENDED', 'SUSPEND_QUEUED', 'SUSPENDING', 'SUSPEND_RETRYING', 'RESTORE_REQUIRED'], true)) {
                $duration['state'] = $state;
            }
            $duration['last_checked_at'] = now()->toIso8601String();
            $recipe['duration_entitlement'] = $duration;
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();
        });
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function markSuspendRetrying(string $simcardId, string $reason): void
    {
        $this->updateSuspendFailureState($simcardId, 'SUSPEND_RETRYING', $reason);
    }

    private function updateSuspendFailureState(string $simcardId, string $state, string $reason): void
    {
        $simcard = Simcard::query()->whereKey($simcardId)->first();
        if ($simcard === null || ! $this->isQuotaCapped($simcard)) {
            return;
        }

        $recipe = (array) $simcard->virtual_fulfillment_recipe;
        $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
        if (strtoupper(trim((string) ($quota['state'] ?? ''))) === 'SUSPENDED') {
            return;
        }

        $quota['state'] = $state;
        $quota['last_suspend_error'] = mb_substr($reason, 0, 500);
        $quota['last_suspend_error_at'] = now()->toIso8601String();
        $recipe['quota'] = $quota;
        $recipe['status'] = 'QUOTA_'.$state;
        $simcard->virtual_fulfillment_recipe = $recipe;
        $simcard->save();
    }

    /** @return array<string,mixed> */
    private function refreshUsageFromProvider(Simcard $simcard): array
    {
        $iccid = $this->decryptIccid($simcard);
        if ($iccid === null) {
            $this->recordLastChecked($simcard, null);

            return ['status' => 'skipped', 'reason' => 'iccid_not_ready'];
        }

        $account = $this->preferredProviderAccount($simcard);
        $providerSim = $this->queryProviderSimThrottled($iccid, $account);
        if ($providerSim === []) {
            $this->recordLastChecked($simcard, null);

            return ['status' => 'skipped', 'reason' => 'provider_usage_not_ready'];
        }

        $providerStatus = strtoupper(trim((string) ($providerSim['esimStatus'] ?? '')));
        $smdpStatus = trim((string) ($providerSim['smdpStatus'] ?? ''));
        $totalBytes = $this->positiveInt($providerSim['totalVolume'] ?? null);
        $usage = $this->nonNegativeInt($providerSim['orderUsage'] ?? null);
        $remaining = $this->nonNegativeInt($providerSim['remain'] ?? null);

        if ($remaining === null && $totalBytes !== null && $usage !== null) {
            $remaining = max(0, $totalBytes - $usage);
        }

        DB::transaction(function () use ($simcard, $providerStatus, $smdpStatus, $totalBytes, $usage, $remaining, $account): void {
            $locked = Simcard::query()->whereKey($simcard->id)->lockForUpdate()->first();
            if ($locked === null || ! $this->isQuotaCapped($locked)) {
                return;
            }

            if ($providerStatus !== '') {
                $locked->esim_status = $providerStatus;
                if ($providerStatus === 'IN_USE') {
                    $locked->state = 'active';
                    $locked->activated_at = $locked->activated_at ?? now();
                }
            }
            if ($smdpStatus !== '') {
                $locked->smdp_status = $smdpStatus;
            }
            if ($totalBytes !== null) {
                $locked->total_volume = $totalBytes;
            }
            if ($usage !== null) {
                $locked->order_usage = $usage;
            }
            if ($remaining !== null) {
                $locked->remaining_volume = $remaining;
            }
            $locked->provider_account = $account;

            $recipe = (array) $locked->virtual_fulfillment_recipe;
            $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
            $quota['last_checked_at'] = now()->toIso8601String();
            $quota['last_observed_usage_bytes'] = $usage;
            $quota['last_provider_total_bytes'] = $totalBytes;
            $quota['last_provider_esim_status'] = $providerStatus !== '' ? $providerStatus : null;
            $recipe['quota'] = $quota;
            $locked->virtual_fulfillment_recipe = $recipe;
            $locked->save();
        });

        return [
            'status' => 'refreshed',
            'total_bytes' => $totalBytes,
            'order_usage' => $usage,
            'remaining_bytes' => $remaining,
            'esim_status' => $providerStatus,
        ];
    }

    private function recordLastChecked(Simcard $simcard, ?int $usage): void
    {
        if (! $this->isQuotaCapped($simcard)) {
            return;
        }

        $recipe = (array) $simcard->virtual_fulfillment_recipe;
        $quota = is_array($recipe['quota'] ?? null) ? $recipe['quota'] : [];
        $quota['last_checked_at'] = now()->toIso8601String();
        if ($usage !== null) {
            $quota['last_observed_usage_bytes'] = $usage;
        }
        $recipe['quota'] = $quota;
        $simcard->virtual_fulfillment_recipe = $recipe;
        $simcard->save();
    }

    /** @return array<string,mixed> */
    private function queryProviderSimThrottled(string $iccid, string $account): array
    {
        return Cache::lock('virtual-esim:quota-provider-throttle', 10)->block(10, function () use ($iccid, $account): array {
            $lastCallAt = Cache::get('virtual-esim:quota-provider-last-call');
            if (is_numeric($lastCallAt)) {
                $elapsed = microtime(true) - (float) $lastCallAt;
                if ($elapsed < self::PROVIDER_MIN_INTERVAL_SECONDS) {
                    usleep((int) ceil((self::PROVIDER_MIN_INTERVAL_SECONDS - $elapsed) * 1_000_000));
                }
            }

            try {
                $response = $this->provider->queryEsim(null, $iccid, $account);
                $providerSim = $this->extractProviderSim($response);
            } finally {
                Cache::put('virtual-esim:quota-provider-last-call', microtime(true), now()->addMinutes(10));
            }

            return $providerSim;
        });
    }

    /** @return array<string,mixed> */
    private function queryProviderSim(string $iccid, string $account): array
    {
        $response = $this->provider->queryEsim(null, $iccid, $account);

        return $this->extractProviderSim($response);
    }

    /** @return array<string,mixed> */
    private function extractProviderSim(array $response): array
    {
        foreach (['obj.esimList.0', 'data.obj.esimList.0', 'data.esimList.0', 'esimList.0'] as $path) {
            $value = data_get($response, $path);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    private function providerMutationSucceeded(array $response): bool
    {
        // eSIMAccess documents `success` as mandatory for suspend/unsuspend.
        // Do not treat a malformed/empty response as success merely because it
        // also omitted an errorCode.
        $success = $response['success'] ?? data_get($response, 'obj.success');
        $successConfirmed = $success === true
            || $success === 1
            || $success === '1'
            || strtolower(trim((string) $success)) === 'true';

        if (! $successConfirmed) {
            return false;
        }

        $errorCode = $response['errorCode'] ?? null;
        if ($errorCode === null) {
            return true;
        }

        return trim((string) $errorCode) === '0';
    }

    private function decryptIccid(Simcard $simcard): ?string
    {
        $encrypted = $simcard->iccid_enc;
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            $iccid = trim($this->crypto->decryptSensitiveValue($encrypted));

            return $iccid !== '' ? $iccid : null;
        } catch (Throwable $exception) {
            Log::warning('Could not decrypt ICCID for virtual eSIM quota enforcement.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => class_basename($exception),
            ]);

            return null;
        }
    }

    private function preferredProviderAccount(Simcard $simcard): string
    {
        return in_array($simcard->provider_account, ['primary', 'legacy'], true)
            ? (string) $simcard->provider_account
            : 'primary';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }
}
