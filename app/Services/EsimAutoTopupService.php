<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Models\SimcardAutoTopupAttempt;
use App\Models\SimcardTopupSession;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use StellarSecurity\Notifications\DTO\NotificationEvent;
use StellarSecurity\Notifications\Facades\Notification;
use Throwable;

class EsimAutoTopupService
{
    private const TRIGGER_PERCENT = 35;
    private const STATE_ARMED = 'ARMED';
    private const STATE_PROCESSING = 'PROCESSING';
    private const STATE_WAITING_REARM = 'WAITING_REARM';
    private const STATE_PAUSED = 'PAUSED';
    private const STATE_DISABLED = 'DISABLED';

    private const ATTEMPT_CLAIMED = 'CLAIMED';
    private const ATTEMPT_EXECUTING = 'EXECUTING';
    private const ATTEMPT_RETRYABLE = 'RETRYABLE';
    private const ATTEMPT_PAYMENT_PENDING = 'PAYMENT_PENDING';
    private const ATTEMPT_FULFILLED = 'FULFILLED';
    private const ATTEMPT_FAILED = 'FAILED';

    private const EXECUTION_LEASE_SECONDS = 120;

    public function __construct(
        private readonly TopupService $topupService,
        private readonly EsimCryptoService $crypto,
        private readonly EsimProvider $provider,
    ) {}

    /**
     * Persist Auto Top-Up against the concrete provisioned eSIM.
     * The caller never controls the trigger percentage.
     */
    public function configureForSimcard(Simcard $simcard, array $payload): SimcardAutoTopup
    {
        if (! filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Auto Top-Up configuration is not enabled.', 422);
        }

        $parentOrderId = $this->uuid((string) ($payload['parent_commerce_order_id'] ?? ''), 'Auto Top-Up parent order id is invalid.');
        $parentOrderItemId = $this->uuid((string) ($payload['parent_commerce_order_item_id'] ?? ''), 'Auto Top-Up parent order item id is invalid.');
        $commerceUnit = max(1, (int) ($payload['commerce_unit'] ?? 1));
        $preferredDataBytes = (int) ($payload['preferred_data_bytes'] ?? 0);
        $preferredDurationDays = isset($payload['preferred_duration_days']) ? (int) $payload['preferred_duration_days'] : null;

        if ($preferredDataBytes <= 0) {
            throw new RuntimeException('Auto Top-Up data allowance is invalid.', 422);
        }

        if (
            (string) $simcard->commerce_order_id !== $parentOrderId
            || (string) $simcard->commerce_order_item_id !== $parentOrderItemId
            || (int) $simcard->commerce_unit !== $commerceUnit
        ) {
            throw new RuntimeException('Auto Top-Up Commerce ownership does not match the provisioned eSIM.', 409);
        }

        return DB::transaction(function () use (
            $simcard,
            $parentOrderId,
            $parentOrderItemId,
            $commerceUnit,
            $preferredDataBytes,
            $preferredDurationDays,
        ): SimcardAutoTopup {
            // Lock the owning SIM row first so provisioning retries and Data-app
            // enable/disable requests cannot race while the config row is absent.
            $lockedSimcard = Simcard::query()
                ->where('id', $simcard->id)
                ->lockForUpdate()
                ->first();

            if ($lockedSimcard === null) {
                throw new RuntimeException('Provisioned eSIM could not be found.', 404);
            }

            if (
                (string) $lockedSimcard->commerce_order_id !== $parentOrderId
                || (string) $lockedSimcard->commerce_order_item_id !== $parentOrderItemId
                || (int) $lockedSimcard->commerce_unit !== $commerceUnit
            ) {
                throw new RuntimeException('Auto Top-Up Commerce ownership does not match the provisioned eSIM.', 409);
            }

            $config = SimcardAutoTopup::query()
                ->where('simcard_id', $lockedSimcard->id)
                ->lockForUpdate()
                ->first();

            if ($config === null) {
                $config = new SimcardAutoTopup();
                $config->id = (string) Str::uuid();
                $config->simcard_id = (string) $simcard->id;
                $config->cycle = 1;
                $config->state = self::STATE_ARMED;
            }

            if (
                $config->exists
                && (
                    (string) $config->parent_commerce_order_id !== $parentOrderId
                    || (string) $config->parent_commerce_order_item_id !== $parentOrderItemId
                    || (int) $config->commerce_unit !== $commerceUnit
                )
            ) {
                throw new RuntimeException('Auto Top-Up is already bound to another Commerce purchase.', 409);
            }

            $meta = is_array($config->meta) ? $config->meta : [];
            $disabledByCustomer = ! empty($meta['disabled_by_customer_at'])
                || ! empty($meta['authorization_revoked_at']);

            $config->parent_commerce_order_id = $parentOrderId;
            $config->parent_commerce_order_item_id = $parentOrderItemId;
            $config->commerce_unit = $commerceUnit;
            $config->trigger_percent = self::TRIGGER_PERCENT;
            $config->preferred_data_bytes = $preferredDataBytes;
            $config->preferred_duration_days = $preferredDurationDays !== null && $preferredDurationDays > 0
                ? $preferredDurationDays
                : null;

            // Provisioning retries must never silently undo a later customer
            // decision from the Data app. A PROCESSING cycle remains resolvable,
            // while every other disabled configuration stays fail-closed.
            if ($disabledByCustomer) {
                $config->enabled = false;
                if ($config->state !== self::STATE_PROCESSING) {
                    $config->state = self::STATE_DISABLED;
                }
            } else {
                $config->enabled = true;
                $config->failure_reason = null;
            }

            $config->meta = array_merge($meta, [
                'version' => 2,
                'pricing_basis' => 'original_variant_regular_price',
                'trigger_semantics' => 'first_observed_at_or_below_threshold',
            ]);
            $config->save();

            return $config;
        });
    }

    /**
     * Evaluate the last known provider usage. A delayed update below 20% is
     * intentionally still eligible because the actual rule is <= 35%.
     *
     * @return array<string,mixed>
     */
    public function processUsage(Simcard|string $simcard): array
    {
        $simcard = is_string($simcard) ? Simcard::find($simcard) : $simcard->fresh();
        if ($simcard === null) {
            return ['status' => 'skipped', 'reason' => 'simcard_not_found'];
        }

        $config = SimcardAutoTopup::query()
            ->where('simcard_id', $simcard->id)
            ->first();

        if ($config === null) {
            return ['status' => 'skipped', 'reason' => 'auto_topup_not_configured'];
        }

        $state = strtoupper(trim((string) $config->state));
        if (! $config->enabled && $state !== self::STATE_PROCESSING) {
            return ['status' => 'disabled'];
        }

        if (strtoupper(trim((string) $simcard->esim_status)) !== 'IN_USE') {
            return ['status' => 'skipped', 'reason' => 'esim_not_in_use'];
        }

        // A customer can turn Auto Top-Up off while an already claimed cycle is
        // crossing the payment boundary. Never claim a new cycle, but continue
        // the exact same idempotent attempt so a successful charge cannot be left
        // without provider fulfillment.
        if ($state === self::STATE_PROCESSING) {
            $attempt = SimcardAutoTopupAttempt::query()
                ->where('auto_topup_id', $config->id)
                ->where('cycle', $config->cycle)
                ->first();

            if ($attempt !== null && in_array($attempt->status, [self::ATTEMPT_CLAIMED, self::ATTEMPT_EXECUTING, self::ATTEMPT_RETRYABLE], true)) {
                return $this->executeAttempt($config, $attempt, $simcard);
            }

            return ['status' => 'processing'];
        }

        if (! $config->enabled) {
            return ['status' => 'disabled'];
        }

        $totalBytes = $this->positiveInt($simcard->total_volume);
        $remainingBytes = $this->nonNegativeInt($simcard->remaining_volume);
        $orderUsage = $this->nonNegativeInt($simcard->order_usage);

        if ($totalBytes === null || $remainingBytes === null || $totalBytes <= 0) {
            return ['status' => 'skipped', 'reason' => 'usage_not_ready'];
        }

        if ($state === self::STATE_WAITING_REARM) {
            if (! $this->allowanceWasReplenished($config, $totalBytes, $remainingBytes, $orderUsage)) {
                return ['status' => 'waiting_rearm'];
            }

            $config = $this->rearm($config);
            if (! $config->enabled || $config->state === self::STATE_DISABLED) {
                return ['status' => 'disabled'];
            }
            $state = strtoupper(trim((string) $config->state));
        }

        if ($state === self::STATE_PAUSED) {
            return ['status' => 'paused', 'reason' => $config->failure_reason];
        }

        $remainingPercent = ($remainingBytes / $totalBytes) * 100;
        if ($remainingPercent > self::TRIGGER_PERCENT) {
            return [
                'status' => 'armed',
                'remaining_percent' => round($remainingPercent, 2),
            ];
        }

        [$config, $attempt] = $this->claimCycle((string) $config->id);
        if ($config === null || $attempt === null) {
            return ['status' => 'skipped', 'reason' => 'cycle_not_claimed'];
        }

        return $this->executeAttempt($config, $attempt, $simcard->fresh() ?? $simcard);
    }

    /**
     * Process Auto Top-Up configs. ARMED and WAITING_REARM configs refresh usage
     * directly from the provider before eligibility is evaluated. PROCESSING
     * configs keep retrying the already-claimed cycle without creating a new one.
     *
     * A provider refresh failure is fail-closed for new cycles: stale stored usage
     * is never allowed to start a fresh automatic card charge.
     *
     * @return array<string,int>
     */
    public function processPending(int $limit = 100, ?string $onlySimcardId = null, bool $refreshOnly = false): array
    {
        $summary = [
            'processed' => 0,
            'triggered' => 0,
            'skipped' => 0,
            'failed' => 0,
            'usage_refresh_attempted' => 0,
            'usage_refreshed' => 0,
            'usage_refresh_skipped' => 0,
            'usage_refresh_failed' => 0,
            'notifications_processed' => 0,
            'notifications_sent' => 0,
            'notifications_skipped' => 0,
            'notifications_failed' => 0,
            'sms_processed' => 0,
            'sms_sent' => 0,
            'sms_skipped' => 0,
            'sms_failed' => 0,
        ];

        $configsQuery = SimcardAutoTopup::query()
            ->where(function ($query): void {
                $query
                    ->where(function ($enabledQuery): void {
                        $enabledQuery
                            ->where('enabled', true)
                            ->whereIn('state', [self::STATE_ARMED, self::STATE_PROCESSING, self::STATE_WAITING_REARM]);
                    })
                    ->orWhere(function ($disabledQuery): void {
                        // A customer-disabled config may still have one already
                        // claimed cycle that must resolve with the same idempotency
                        // key. It can never claim a new cycle while disabled.
                        $disabledQuery
                            ->where('enabled', false)
                            ->where('state', self::STATE_PROCESSING);
                    });
            });

        $onlySimcardId = trim((string) $onlySimcardId);
        if ($onlySimcardId !== '') {
            $configsQuery->where('simcard_id', $onlySimcardId);
        }

        $configs = $configsQuery
            ->orderBy('updated_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($configs as $config) {
            $summary['processed']++;

            try {
                if ($refreshOnly && $config->state === self::STATE_PROCESSING) {
                    $summary['skipped']++;
                    continue;
                }

                if (in_array($config->state, [self::STATE_ARMED, self::STATE_WAITING_REARM], true)) {
                    $simcard = Simcard::query()->where('id', $config->simcard_id)->first();

                    if ($simcard === null) {
                        $summary['skipped']++;
                        continue;
                    }

                    if (strtoupper(trim((string) $simcard->esim_status)) !== 'IN_USE') {
                        $summary['skipped']++;
                        continue;
                    }

                    $summary['usage_refresh_attempted']++;
                    $refresh = $this->refreshUsageFromProvider($simcard);

                    if (($refresh['status'] ?? '') === 'refreshed') {
                        $summary['usage_refreshed']++;

                        if ($refreshOnly) {
                            $summary['skipped']++;
                            continue;
                        }
                    } elseif (($refresh['status'] ?? '') === 'failed') {
                        // Never open a new charge cycle from stale usage when the
                        // provider cannot confirm the current allowance.
                        $summary['usage_refresh_failed']++;
                        $summary['skipped']++;
                        continue;
                    } else {
                        $summary['usage_refresh_skipped']++;
                        $summary['skipped']++;
                        continue;
                    }
                }

                $result = $this->processUsage((string) $config->simcard_id);
                if (in_array($result['status'] ?? '', ['payment_pending', 'processing', 'fulfilled', 'retryable'], true)) {
                    $summary['triggered']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
                Log::warning('Scheduled eSIM Auto Top-Up processing failed.', [
                    'auto_topup_id' => (string) $config->id,
                    'simcard_id' => (string) $config->simcard_id,
                    'exception' => basename(str_replace('\\', '/', get_class($exception))),
                ]);
            }
        }

        $notificationSummary = $this->retryPendingSuccessNotifications($limit);
        $summary['notifications_processed'] = $notificationSummary['processed'];
        $summary['notifications_sent'] = $notificationSummary['sent'];
        $summary['notifications_skipped'] = $notificationSummary['skipped'];
        $summary['notifications_failed'] = $notificationSummary['failed'];

        $smsSummary = $this->retryPendingSuccessSms($limit);
        $summary['sms_processed'] = $smsSummary['processed'];
        $summary['sms_sent'] = $smsSummary['sent'];
        $summary['sms_skipped'] = $smsSummary['skipped'];
        $summary['sms_failed'] = $smsSummary['failed'];

        return $summary;
    }

    /**
     * Refresh the latest eSIM allowance directly from the provider. This is the
     * reliable fallback when DATA_USAGE webhooks are delayed or not emitted.
     *
     * This method is read-only at the provider. It only updates our local SIM
     * snapshot after a successful response.
     *
     * @return array<string,mixed>
     */
    private function refreshUsageFromProvider(Simcard $simcard): array
    {
        if (strtoupper(trim((string) $simcard->esim_status)) !== 'IN_USE') {
            return ['status' => 'skipped', 'reason' => 'esim_not_in_use'];
        }

        if (empty($simcard->iccid_enc)) {
            return ['status' => 'skipped', 'reason' => 'iccid_not_ready'];
        }

        try {
            $iccid = trim($this->crypto->decryptSensitiveValue((string) $simcard->iccid_enc));
            if ($iccid === '') {
                return ['status' => 'skipped', 'reason' => 'iccid_not_ready'];
            }

            $preferredAccount = in_array((string) $simcard->provider_account, ['primary', 'legacy'], true)
                ? (string) $simcard->provider_account
                : 'legacy';

            $account = $this->provider->resolveAccountForEsim(null, $iccid, $preferredAccount);
            $response = $this->provider->queryEsim(null, $iccid, $account);
            $providerSim = data_get($response, 'obj.esimList.0')
                ?? data_get($response, 'data.obj.esimList.0')
                ?? data_get($response, 'data.esimList.0')
                ?? data_get($response, 'esimList.0');

            if (! is_array($providerSim)) {
                return ['status' => 'skipped', 'reason' => 'provider_usage_not_ready'];
            }

            $returnedIccid = trim((string) ($providerSim['iccid'] ?? ''));
            if ($returnedIccid !== '' && ! hash_equals($iccid, $returnedIccid)) {
                Log::warning('eSIM Auto Top-Up provider usage response ICCID mismatch.', [
                    'simcard_id' => (string) $simcard->id,
                    'provider_account' => $account,
                ]);

                return ['status' => 'failed', 'reason' => 'provider_iccid_mismatch'];
            }

            $providerStatus = strtoupper(trim((string) ($providerSim['esimStatus'] ?? '')));
            $smdpStatus = trim((string) ($providerSim['smdpStatus'] ?? ''));
            $totalBytes = $this->positiveInt($providerSim['totalVolume'] ?? null);
            $orderUsage = $this->nonNegativeInt($providerSim['orderUsage'] ?? null);
            $remainingBytes = $this->nonNegativeInt($providerSim['remain'] ?? null);

            if ($remainingBytes === null && $totalBytes !== null && $orderUsage !== null) {
                $remainingBytes = max(0, $totalBytes - $orderUsage);
            }

            $hadUsageBefore = $this->positiveInt($simcard->total_volume) !== null
                && $this->nonNegativeInt($simcard->remaining_volume) !== null;

            DB::transaction(function () use (
                $simcard,
                $account,
                $providerStatus,
                $smdpStatus,
                $totalBytes,
                $orderUsage,
                $remainingBytes,
            ): void {
                $locked = Simcard::query()->where('id', $simcard->id)->lockForUpdate()->first();
                if ($locked === null) {
                    return;
                }

                $locked->provider_account = $account;

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

                if ($orderUsage !== null) {
                    $locked->order_usage = $orderUsage;
                }

                if ($remainingBytes !== null) {
                    $locked->remaining_volume = $remainingBytes;
                }

                $locked->save();
            });

            if ($totalBytes === null || $remainingBytes === null || $totalBytes <= 0) {
                return [
                    'status' => 'skipped',
                    'reason' => 'provider_usage_not_ready',
                    'esim_status' => $providerStatus !== '' ? $providerStatus : null,
                ];
            }

            $remainingPercent = ($remainingBytes / $totalBytes) * 100;

            if (! $hadUsageBefore || $remainingPercent <= self::TRIGGER_PERCENT) {
                Log::info('eSIM Auto Top-Up provider usage refreshed.', [
                    'simcard_id' => (string) $simcard->id,
                    'provider_account' => $account,
                    'esim_status' => $providerStatus !== '' ? $providerStatus : (string) $simcard->esim_status,
                    'remaining_percent' => round($remainingPercent, 2),
                    'eligible' => ($providerStatus === '' || $providerStatus === 'IN_USE')
                        && $remainingPercent <= self::TRIGGER_PERCENT,
                ]);
            }

            return [
                'status' => 'refreshed',
                'esim_status' => $providerStatus !== '' ? $providerStatus : (string) $simcard->esim_status,
                'total_bytes' => $totalBytes,
                'order_usage' => $orderUsage,
                'remaining_bytes' => $remainingBytes,
                'remaining_percent' => round($remainingPercent, 2),
            ];
        } catch (Throwable $exception) {
            Log::warning('eSIM Auto Top-Up provider usage refresh failed.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return ['status' => 'failed', 'reason' => 'provider_usage_refresh_failed'];
        }
    }

    /**
     * Retry fulfilled Auto Top-Up confirmation emails that were not acknowledged
     * by the Notification API. The Notification API event is itself idempotent.
     *
     * @return array{processed:int,sent:int,skipped:int,failed:int}
     */
    public function retryPendingSuccessNotifications(int $limit = 100): array
    {
        $summary = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        $attempts = SimcardAutoTopupAttempt::query()
            ->where('status', self::ATTEMPT_FULFILLED)
            ->whereNull('notification_sent_at')
            ->where(function ($query): void {
                $query->whereNull('notification_attempted_at')
                    ->orWhere('notification_attempted_at', '<=', now()->subMinutes(5));
            })
            ->orderBy('fulfilled_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($attempts as $attempt) {
            $summary['processed']++;

            $result = $this->sendSuccessNotificationForAttempt((string) $attempt->id);
            if ($result === 'sent') {
                $summary['sent']++;
            } elseif ($result === 'failed') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * Retry Auto Top-Up success SMS deliveries that were already attempted but
     * not confirmed as sent. Existing historical fulfilled attempts are not
     * backfilled, so deploying this feature never sends retroactive SMS messages.
     *
     * @return array{processed:int,sent:int,skipped:int,failed:int}
     */
    public function retryPendingSuccessSms(int $limit = 100): array
    {
        $summary = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        $attempts = SimcardAutoTopupAttempt::query()
            ->where('status', self::ATTEMPT_FULFILLED)
            ->whereNull('sms_sent_at')
            ->whereNotNull('sms_attempted_at')
            ->where('sms_attempted_at', '<=', now()->subMinutes(5))
            ->orderBy('fulfilled_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($attempts as $attempt) {
            $summary['processed']++;

            $result = $this->sendSuccessSmsForAttempt((string) $attempt->id);
            if ($result === 'sent') {
                $summary['sent']++;
            } elseif ($result === 'failed') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    public function markPaymentFailed(string $topupSessionId, ?string $reason = null): void
    {
        $topupSessionId = $this->uuid($topupSessionId, 'Top-up session id is invalid.');

        DB::transaction(function () use ($topupSessionId, $reason): void {
            $attempt = SimcardAutoTopupAttempt::query()
                ->where('topup_session_id', $topupSessionId)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status === self::ATTEMPT_FULFILLED) {
                return;
            }

            $failureReason = trim((string) $reason) ?: 'Auto Top-Up payment failed.';
            $attempt->status = self::ATTEMPT_FAILED;
            $attempt->failure_reason = mb_substr($failureReason, 0, 2000);
            $attempt->save();

            $config = SimcardAutoTopup::query()->where('id', $attempt->auto_topup_id)->lockForUpdate()->first();
            if ($config !== null) {
                $meta = is_array($config->meta) ? $config->meta : [];
                if ($config->enabled) {
                    $config->state = self::STATE_PAUSED;
                } else {
                    $meta['state_before_disable'] = self::STATE_PAUSED;
                    $config->state = self::STATE_DISABLED;
                    $config->meta = $meta;
                }
                $config->failure_reason = $attempt->failure_reason;
                $config->save();
            }
        });
    }

    public function markFulfilled(string $topupSessionId, ?string $commerceOrderId = null): void
    {
        $topupSessionId = $this->uuid($topupSessionId, 'Top-up session id is invalid.');

        $attemptId = DB::transaction(function () use ($topupSessionId, $commerceOrderId): ?string {
            $attempt = SimcardAutoTopupAttempt::query()
                ->where('topup_session_id', $topupSessionId)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                return null;
            }

            $attempt->status = self::ATTEMPT_FULFILLED;
            $attempt->commerce_order_id = $commerceOrderId ?: $attempt->commerce_order_id;
            $attempt->fulfilled_at = $attempt->fulfilled_at ?: now();
            $attempt->failure_reason = null;
            $attempt->save();

            $config = SimcardAutoTopup::query()->where('id', $attempt->auto_topup_id)->lockForUpdate()->first();
            if ($config !== null) {
                $meta = is_array($config->meta) ? $config->meta : [];
                if ($config->enabled) {
                    $config->state = self::STATE_WAITING_REARM;
                } else {
                    // The top-up already crossed the payment boundary before the
                    // customer switched it off. Finish delivery, then remain off.
                    // Re-enabling later must first observe the replenished allowance.
                    $meta['state_before_disable'] = self::STATE_WAITING_REARM;
                    $config->state = self::STATE_DISABLED;
                    $config->meta = $meta;
                }
                $config->last_success_at = now();
                $config->failure_reason = null;
                $config->save();
            }

            return (string) $attempt->id;
        });

        if ($attemptId !== null) {
            // The provider fulfillment is already committed. Notification failures
            // must never roll back or change the successful top-up itself.
            $this->sendSuccessNotificationForAttempt($attemptId);
            $this->sendSuccessSmsForAttempt($attemptId);
        }
    }

    /** @return array{0:SimcardAutoTopup|null,1:SimcardAutoTopupAttempt|null} */
    private function claimCycle(string $configId): array
    {
        $configSnapshot = SimcardAutoTopup::query()->where('id', $configId)->first();
        if ($configSnapshot === null) {
            return [null, null];
        }

        $simcardId = (string) $configSnapshot->simcard_id;

        return DB::transaction(function () use ($configId, $simcardId): array {
            // Use the same SIM -> config lock order as management/provisioning.
            // The final config checks below remain authoritative.
            $simcard = Simcard::query()->where('id', $simcardId)->lockForUpdate()->first();
            if ($simcard === null || strtoupper(trim((string) $simcard->esim_status)) !== 'IN_USE') {
                return [null, null];
            }

            $config = SimcardAutoTopup::query()->where('id', $configId)->lockForUpdate()->first();
            if (
                $config === null
                || (string) $config->simcard_id !== (string) $simcard->id
                || ! $config->enabled
                || $config->state !== self::STATE_ARMED
            ) {
                return [null, null];
            }

            $totalBytes = $this->positiveInt($simcard->total_volume);
            $remainingBytes = $this->nonNegativeInt($simcard->remaining_volume);
            $orderUsage = $this->nonNegativeInt($simcard->order_usage);
            if ($totalBytes === null || $remainingBytes === null || $totalBytes <= 0) {
                return [null, null];
            }

            $remainingPercent = ($remainingBytes / $totalBytes) * 100;
            if ($remainingPercent > self::TRIGGER_PERCENT) {
                return [null, null];
            }

            $attempt = SimcardAutoTopupAttempt::query()
                ->where('auto_topup_id', $config->id)
                ->where('cycle', $config->cycle)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                $attempt = new SimcardAutoTopupAttempt();
                $attempt->id = (string) Str::uuid();
                $attempt->auto_topup_id = (string) $config->id;
                $attempt->cycle = (int) $config->cycle;
                $attempt->attempt_key = hash('sha256', implode('|', [
                    'esim-auto-topup',
                    (string) $config->id,
                    (string) $config->cycle,
                ]));
                $attempt->status = self::ATTEMPT_CLAIMED;
                $attempt->observed_total_bytes = $totalBytes;
                $attempt->observed_remaining_bytes = $remainingBytes;
                $attempt->observed_order_usage = $orderUsage;
                $attempt->observed_remaining_percent = round($remainingPercent, 2);
                $attempt->save();
            }

            $config->state = self::STATE_PROCESSING;
            $config->last_trigger_total_bytes = $totalBytes;
            $config->last_trigger_remaining_bytes = $remainingBytes;
            $config->last_trigger_order_usage = $orderUsage;
            $config->last_triggered_at = now();
            $config->failure_reason = null;
            $config->save();

            return [$config->fresh(), $attempt->fresh()];
        });
    }

    /** @return array<string,mixed> */
    private function executeAttempt(SimcardAutoTopup $config, SimcardAutoTopupAttempt $attempt, Simcard $simcard): array
    {
        $claimedAttempt = $this->claimAttemptExecution((string) $attempt->id);
        if ($claimedAttempt === null) {
            return ['status' => 'processing'];
        }

        $attempt = $claimedAttempt;

        try {
            if ($attempt->topup_session_id === null) {
                $session = $this->topupService->prepareAutoTopupSession(
                    simcard: $simcard,
                    preferredDataBytes: (int) $config->preferred_data_bytes,
                    preferredDurationDays: $config->preferred_duration_days !== null ? (int) $config->preferred_duration_days : null,
                    attemptKey: (string) $attempt->attempt_key,
                );

                $attempt->topup_session_id = (string) $session->id;
                $attempt->meta = array_merge((array) $attempt->meta, [
                    'package_code' => (string) $session->package_code,
                    'prepared_at' => now()->toIso8601String(),
                ]);
                $attempt->save();
            }

            $session = SimcardTopupSession::query()->where('id', $attempt->topup_session_id)->first();
            if ($session === null) {
                throw new RuntimeException('Prepared Auto Top-Up session could not be found.', 500);
            }

            // This is the atomic customer-disable boundary. If OFF won before a
            // Commerce request started, cancel this cycle without charging. If a
            // request had already started (including a lost-response retry), keep
            // resolving the same idempotent attempt so payment cannot be separated
            // from provider fulfillment.
            if (! $this->beginCommerceRequest((string) $config->id, (string) $attempt->id)) {
                return [
                    'status' => 'disabled',
                    'remaining_percent' => $attempt->observed_remaining_percent,
                    'topup_session_id' => (string) $attempt->topup_session_id,
                    'commerce_order_id' => null,
                ];
            }

            $result = $this->requestCommerceCharge($config, $attempt, (string) $session->package_code);
            $paymentState = $this->recordPaymentRequested((string) $attempt->id, $result);

            return [
                'status' => $paymentState['status'],
                'remaining_percent' => $paymentState['attempt']->observed_remaining_percent,
                'topup_session_id' => (string) $paymentState['attempt']->topup_session_id,
                'commerce_order_id' => $paymentState['attempt']->commerce_order_id,
            ];
        } catch (ConnectionException $exception) {
            $this->markRetryable($config, $attempt, 'Commerce connection failed.');

            return ['status' => 'retryable', 'reason' => 'commerce_connection_failed'];
        } catch (RuntimeException $exception) {
            $code = (int) $exception->getCode();
            if ($code >= 500 || $code === 429 || $code === 0) {
                $this->markRetryable($config, $attempt, $exception->getMessage());

                return ['status' => 'retryable', 'reason' => $exception->getMessage()];
            }

            $this->pause($config, $attempt, $exception->getMessage());

            return ['status' => 'paused', 'reason' => $exception->getMessage()];
        } catch (Throwable $exception) {
            $this->markRetryable($config, $attempt, 'Unexpected Auto Top-Up processing error.');

            Log::warning('Unexpected eSIM Auto Top-Up processing error.', [
                'auto_topup_id' => (string) $config->id,
                'attempt_id' => (string) $attempt->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return ['status' => 'retryable', 'reason' => 'unexpected_processing_error'];
        }
    }

    /**
     * Acquire a short database-backed execution lease for one Auto Top-Up attempt.
     * Concurrent webhook and polling workers therefore do not both call Commerce.
     * If a worker dies mid-flight, the same attempt can be reclaimed after the
     * lease expires; Commerce and Stripe still receive the same idempotency key.
     */
    private function claimAttemptExecution(string $attemptId): ?SimcardAutoTopupAttempt
    {
        return DB::transaction(function () use ($attemptId): ?SimcardAutoTopupAttempt {
            $attempt = SimcardAutoTopupAttempt::query()
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                return null;
            }

            if (in_array($attempt->status, [self::ATTEMPT_PAYMENT_PENDING, self::ATTEMPT_FULFILLED, self::ATTEMPT_FAILED], true)) {
                return null;
            }

            if ($attempt->status === self::ATTEMPT_EXECUTING) {
                $updatedAt = $attempt->updated_at;
                if ($updatedAt !== null && $updatedAt->gt(now()->subSeconds(self::EXECUTION_LEASE_SECONDS))) {
                    return null;
                }
            } elseif (! in_array($attempt->status, [self::ATTEMPT_CLAIMED, self::ATTEMPT_RETRYABLE], true)) {
                return null;
            }

            $attempt->status = self::ATTEMPT_EXECUTING;
            $attempt->meta = array_merge((array) $attempt->meta, [
                'execution_claimed_at' => now()->toIso8601String(),
            ]);
            $attempt->save();

            return $attempt->fresh();
        });
    }

    private function beginCommerceRequest(string $configId, string $attemptId): bool
    {
        return DB::transaction(function () use ($configId, $attemptId): bool {
            // Payment/fulfillment callbacks use attempt -> config, so keep the
            // same lock order here to avoid an inversion during lost-response retries.
            $attempt = SimcardAutoTopupAttempt::query()
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();
            $config = SimcardAutoTopup::query()
                ->where('id', $configId)
                ->lockForUpdate()
                ->first();

            if ($config === null || $attempt === null || (string) $attempt->auto_topup_id !== (string) $config->id) {
                throw new RuntimeException('Auto Top-Up execution state could not be found.', 500);
            }

            $meta = is_array($attempt->meta) ? $attempt->meta : [];
            $paymentBoundaryStarted = ! empty($meta['commerce_request_started_at'])
                || $attempt->payment_requested_at !== null
                || trim((string) $attempt->commerce_order_id) !== ''
                || trim((string) $attempt->stripe_payment_intent_id) !== '';

            if (! $config->enabled && ! $paymentBoundaryStarted) {
                $now = now();
                $attempt->status = self::ATTEMPT_FAILED;
                $attempt->failure_reason = 'Auto Top-Up was disabled before payment started.';
                $attempt->meta = array_merge($meta, [
                    'cancelled_by_customer_at' => $now->toIso8601String(),
                    'cancelled_before_payment' => true,
                ]);
                $attempt->save();

                if ($attempt->topup_session_id !== null) {
                    $session = SimcardTopupSession::query()
                        ->where('id', $attempt->topup_session_id)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $session !== null
                        && strtoupper(trim((string) $session->status)) === 'PENDING_PAYMENT'
                        && trim((string) $session->commerce_order_id) === ''
                    ) {
                        $session->status = 'CANCELLED';
                        $session->failure_reason = 'Auto Top-Up was disabled before payment started.';
                        $session->save();
                    }
                }

                $configMeta = is_array($config->meta) ? $config->meta : [];
                $configMeta['state_before_disable'] = self::STATE_ARMED;
                $config->state = self::STATE_DISABLED;
                $config->failure_reason = null;
                $config->meta = $configMeta;
                $config->save();

                return false;
            }

            if (empty($meta['commerce_request_started_at'])) {
                $meta['commerce_request_started_at'] = now()->toIso8601String();
                $attempt->meta = $meta;
                $attempt->save();
            }

            return true;
        });
    }

    /** @return array<string,mixed> */
    private function requestCommerceCharge(SimcardAutoTopup $config, SimcardAutoTopupAttempt $attempt, string $packageCode): array
    {
        $url = trim((string) config('services.stellar_commerce.auto_topup_charge_url', ''));
        if ($url === '') {
            throw new RuntimeException('Commerce Auto Top-Up charge URL is not configured.', 500);
        }

        $username = (string) config('services.stellar_commerce.username', '');
        $password = (string) config('services.stellar_commerce.password', '');
        if ($username === '' || $password === '') {
            throw new RuntimeException('Commerce Auto Top-Up credentials are not configured.', 500);
        }

        $response = Http::asJson()
            ->withBasicAuth($username, $password)
            ->connectTimeout(10)
            ->timeout(45)
            ->post($url, [
                'parent_order_id' => (string) $config->parent_commerce_order_id,
                'parent_order_item_id' => (string) $config->parent_commerce_order_item_id,
                'simcard_id' => (string) $config->simcard_id,
                'commerce_unit' => (int) $config->commerce_unit,
                'topup_session_id' => (string) $attempt->topup_session_id,
                'package_code' => $packageCode,
                'attempt_key' => (string) $attempt->attempt_key,
            ]);

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->status() === 429 || $response->serverError()) {
            throw new RuntimeException('Commerce Auto Top-Up charge is temporarily unavailable.', $response->status());
        }

        if (! $response->successful()) {
            $message = trim((string) ($body['response_message'] ?? $body['message'] ?? 'Auto Top-Up payment was rejected.'));
            throw new RuntimeException($message, $response->status());
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (trim((string) ($data['order_id'] ?? '')) === '') {
            throw new RuntimeException('Commerce Auto Top-Up response is missing the order id.', 502);
        }

        return $data;
    }

    /** @param array<string,mixed> $result
     *  @return array{status:string,attempt:SimcardAutoTopupAttempt}
     */
    private function recordPaymentRequested(string $attemptId, array $result): array
    {
        $state = DB::transaction(function () use ($attemptId, $result): array {
            $attempt = SimcardAutoTopupAttempt::query()->where('id', $attemptId)->lockForUpdate()->firstOrFail();

            $attempt->commerce_order_id = $result['order_id'] ?? $attempt->commerce_order_id;
            $attempt->stripe_payment_intent_id = $result['payment_intent_id'] ?? $attempt->stripe_payment_intent_id;
            $attempt->payment_requested_at = $attempt->payment_requested_at ?: now();
            $attempt->meta = array_merge((array) $attempt->meta, array_filter([
                'commerce_payment_intent_status' => $result['payment_intent_status'] ?? null,
                'commerce_order_status' => $result['order_status'] ?? null,
                'commerce_amount_cents' => isset($result['amount_cents']) ? (int) $result['amount_cents'] : null,
                'commerce_currency' => isset($result['currency']) ? strtoupper(trim((string) $result['currency'])) : null,
            ], static fn ($value) => $value !== null && $value !== ''));

            // Stripe webhooks can beat the synchronous Commerce response. Preserve
            // terminal state, but still persist the charge metadata needed by the
            // confirmation email.
            if ($attempt->status === self::ATTEMPT_FULFILLED) {
                $attempt->save();
                return ['status' => 'fulfilled', 'attempt' => $attempt->fresh()];
            }

            if ($attempt->status === self::ATTEMPT_FAILED) {
                $attempt->save();
                return ['status' => 'paused', 'attempt' => $attempt->fresh()];
            }

            $attempt->status = self::ATTEMPT_PAYMENT_PENDING;
            $attempt->failure_reason = null;
            $attempt->save();

            return ['status' => 'payment_pending', 'attempt' => $attempt->fresh()];
        });

        if (($state['status'] ?? null) === 'fulfilled') {
            $this->sendSuccessNotificationForAttempt((string) $state['attempt']->id, true);
            $this->sendSuccessSmsForAttempt((string) $state['attempt']->id, true);
            $state['attempt'] = $state['attempt']->fresh();
        }

        return $state;
    }

    private function markRetryable(SimcardAutoTopup $config, SimcardAutoTopupAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($config, $attempt, $reason): void {
            $lockedAttempt = SimcardAutoTopupAttempt::query()->where('id', $attempt->id)->lockForUpdate()->first();
            if ($lockedAttempt === null || in_array($lockedAttempt->status, [self::ATTEMPT_FULFILLED, self::ATTEMPT_FAILED], true)) {
                return;
            }

            $lockedAttempt->status = self::ATTEMPT_RETRYABLE;
            $lockedAttempt->failure_reason = mb_substr($reason, 0, 2000);
            $lockedAttempt->save();

            $lockedConfig = SimcardAutoTopup::query()->where('id', $config->id)->lockForUpdate()->first();
            if ($lockedConfig !== null && $lockedConfig->state !== self::STATE_WAITING_REARM && $lockedConfig->state !== self::STATE_PAUSED) {
                $lockedConfig->state = self::STATE_PROCESSING;
                $lockedConfig->failure_reason = $lockedAttempt->failure_reason;
                $lockedConfig->save();
            }
        });
    }

    private function pause(SimcardAutoTopup $config, SimcardAutoTopupAttempt $attempt, string $reason): void
    {
        DB::transaction(function () use ($config, $attempt, $reason): void {
            $lockedAttempt = SimcardAutoTopupAttempt::query()->where('id', $attempt->id)->lockForUpdate()->first();
            if ($lockedAttempt === null || $lockedAttempt->status === self::ATTEMPT_FULFILLED) {
                return;
            }

            $lockedAttempt->status = self::ATTEMPT_FAILED;
            $lockedAttempt->failure_reason = mb_substr($reason, 0, 2000);
            $lockedAttempt->save();

            $lockedConfig = SimcardAutoTopup::query()->where('id', $config->id)->lockForUpdate()->first();
            if ($lockedConfig !== null && $lockedConfig->state !== self::STATE_WAITING_REARM) {
                $meta = is_array($lockedConfig->meta) ? $lockedConfig->meta : [];
                if ($lockedConfig->enabled) {
                    $lockedConfig->state = self::STATE_PAUSED;
                } else {
                    $meta['state_before_disable'] = self::STATE_PAUSED;
                    $lockedConfig->state = self::STATE_DISABLED;
                    $lockedConfig->meta = $meta;
                }
                $lockedConfig->failure_reason = $lockedAttempt->failure_reason;
                $lockedConfig->save();
            }
        });
    }

    /**
     * Send the customer confirmation only after provider fulfillment succeeded.
     * Returns sent|skipped|failed and never throws into the fulfillment path.
     */
    private function sendSuccessNotificationForAttempt(string $attemptId, bool $force = false): string
    {
        $claimed = DB::transaction(function () use ($attemptId, $force): bool {
            $attempt = SimcardAutoTopupAttempt::query()
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status !== self::ATTEMPT_FULFILLED || $attempt->notification_sent_at !== null) {
                return false;
            }

            if (! $force && $attempt->notification_attempted_at !== null && $attempt->notification_attempted_at->gt(now()->subMinutes(2))) {
                return false;
            }

            $attempt->notification_attempted_at = now();
            $attempt->notification_failure_reason = null;
            $attempt->save();

            return true;
        });

        if (! $claimed) {
            return 'skipped';
        }

        $attempt = SimcardAutoTopupAttempt::query()->where('id', $attemptId)->first();
        if ($attempt === null) {
            return 'skipped';
        }

        $config = SimcardAutoTopup::query()->where('id', $attempt->auto_topup_id)->first();
        $session = $attempt->topup_session_id !== null
            ? SimcardTopupSession::query()->where('id', $attempt->topup_session_id)->first()
            : null;
        $simcard = $config !== null
            ? Simcard::query()->where('id', $config->simcard_id)->first()
            : null;

        $meta = is_array($attempt->meta) ? $attempt->meta : [];
        $amountCents = isset($meta['commerce_amount_cents']) && is_numeric($meta['commerce_amount_cents'])
            ? (int) $meta['commerce_amount_cents']
            : 0;
        $currency = strtoupper(trim((string) ($meta['commerce_currency'] ?? '')));

        if ($config === null || $session === null || $simcard === null) {
            return $this->recordNotificationFailure($attemptId, 'Auto Top-Up confirmation context is incomplete.');
        }

        if ($amountCents <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            // In the webhook-race case provider fulfillment may finish before the
            // synchronous Commerce response reaches Simcard API. recordPaymentRequested()
            // will retry immediately after it stores the actual charge metadata.
            return $this->recordNotificationFailure($attemptId, 'Auto Top-Up charge metadata is not available yet.');
        }

        $dataAddedBytes = (int) ($session->data_bytes ?? 0);
        if ($dataAddedBytes <= 0) {
            return $this->recordNotificationFailure($attemptId, 'Auto Top-Up data allowance is missing.');
        }

        $commerceOrderId = trim((string) ($attempt->commerce_order_id ?? ''));
        if ($commerceOrderId === '' || ! Str::isUuid($commerceOrderId)) {
            return $this->recordNotificationFailure($attemptId, 'Auto Top-Up Commerce order id is missing.');
        }

        $email = $this->resolveSimcardEmail($simcard);
        if ($email === null) {
            return $this->recordNotificationFailure($attemptId, 'Customer email is unavailable for Auto Top-Up confirmation.');
        }

        $idempotencyKey = 'esim_auto_topup_success_' . (string) $attempt->id;

        try {
            Notification::send(
                NotificationEvent::make('esim_auto_topup_success')
                    ->product('stellar-data')
                    ->email($email)
                    ->payload(array_filter([
                        'simcard_id' => (string) $simcard->id,
                        'topup_session_id' => (string) $session->id,
                        'auto_topup_cycle' => (int) $attempt->cycle,
                        'data_added_bytes' => $dataAddedBytes,
                        'amount_cents' => $amountCents,
                        'currency' => $currency,
                        'commerce_order_id' => $commerceOrderId,
                        'package_name' => trim((string) ($session->package_name ?? '')),
                        'iccid_last4' => trim((string) ($simcard->iccid_last4 ?? '')),
                        'remaining_percent_at_trigger' => $attempt->observed_remaining_percent,
                        'manage_url' => 'https://data.stellarsecurity.com/',
                        'support_url' => 'https://stellarsecurity.com/contact-us',
                    ], static fn ($value) => $value !== null && $value !== ''))
                    ->idempotencyKey($idempotencyKey)
            );

            SimcardAutoTopupAttempt::query()
                ->where('id', $attemptId)
                ->whereNull('notification_sent_at')
                ->update([
                    'notification_sent_at' => now(),
                    'notification_failure_reason' => null,
                ]);

            Log::info('eSIM Auto Top-Up success notification sent.', [
                'simcard_id' => (string) $simcard->id,
                'auto_topup_attempt_id' => (string) $attempt->id,
                'topup_session_id' => (string) $session->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return 'sent';
        } catch (Throwable $exception) {
            $reason = 'Notification API delivery failed: ' . basename(str_replace('\\', '/', get_class($exception)));
            $this->recordNotificationFailure($attemptId, $reason);

            Log::warning('Failed to send eSIM Auto Top-Up success notification.', [
                'simcard_id' => (string) $simcard->id,
                'auto_topup_attempt_id' => (string) $attempt->id,
                'topup_session_id' => (string) $session->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return 'failed';
        }
    }

    private function recordNotificationFailure(string $attemptId, string $reason): string
    {
        SimcardAutoTopupAttempt::query()
            ->where('id', $attemptId)
            ->whereNull('notification_sent_at')
            ->update([
                'notification_failure_reason' => mb_substr($reason, 0, 2000),
            ]);

        return 'failed';
    }

    /**
     * Send an Auto Top-Up completion SMS through the existing eSIMAccess SMS
     * channel. This runs only after provider fulfillment has committed.
     *
     * The SMS state is deliberately independent from the email notification
     * state. A delivery failure can never change payment or top-up state.
     *
     * Returns sent|skipped|failed and never throws into the fulfillment path.
     */
    public function sendSuccessSmsForAttempt(string $attemptId, bool $force = false): string
    {
        $claimed = DB::transaction(function () use ($attemptId, $force): bool {
            $attempt = SimcardAutoTopupAttempt::query()
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status !== self::ATTEMPT_FULFILLED || $attempt->sms_sent_at !== null) {
                return false;
            }

            if (! $force && $attempt->sms_attempted_at !== null && $attempt->sms_attempted_at->gt(now()->subMinutes(2))) {
                return false;
            }

            $attempt->sms_attempted_at = now();
            $attempt->sms_failure_reason = null;
            $attempt->save();

            return true;
        });

        if (! $claimed) {
            return 'skipped';
        }

        $attempt = SimcardAutoTopupAttempt::query()->where('id', $attemptId)->first();
        if ($attempt === null) {
            return 'skipped';
        }

        $config = SimcardAutoTopup::query()->where('id', $attempt->auto_topup_id)->first();
        $session = $attempt->topup_session_id !== null
            ? SimcardTopupSession::query()->where('id', $attempt->topup_session_id)->first()
            : null;
        $simcard = $config !== null
            ? Simcard::query()->where('id', $config->simcard_id)->first()
            : null;

        $meta = is_array($attempt->meta) ? $attempt->meta : [];
        $amountCents = isset($meta['commerce_amount_cents']) && is_numeric($meta['commerce_amount_cents'])
            ? (int) $meta['commerce_amount_cents']
            : 0;
        $currency = strtoupper(trim((string) ($meta['commerce_currency'] ?? '')));

        if ($config === null || $session === null || $simcard === null) {
            return $this->recordSmsFailure($attemptId, 'Auto Top-Up SMS context is incomplete.');
        }

        if ($amountCents <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return $this->recordSmsFailure($attemptId, 'Auto Top-Up charge metadata is not available yet.');
        }

        $dataAddedBytes = (int) ($session->data_bytes ?? 0);
        if ($dataAddedBytes <= 0) {
            return $this->recordSmsFailure($attemptId, 'Auto Top-Up data allowance is missing.');
        }

        if (empty($simcard->iccid_enc)) {
            return $this->recordSmsFailure($attemptId, 'eSIM ICCID is unavailable for Auto Top-Up SMS.');
        }

        try {
            $iccid = trim($this->crypto->decryptSensitiveValue((string) $simcard->iccid_enc));
        } catch (Throwable $exception) {
            Log::warning('Could not decrypt ICCID for Auto Top-Up success SMS.', [
                'simcard_id' => (string) $simcard->id,
                'auto_topup_attempt_id' => (string) $attempt->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return $this->recordSmsFailure($attemptId, 'Could not resolve eSIM ICCID for Auto Top-Up SMS.');
        }

        if ($iccid === '') {
            return $this->recordSmsFailure($attemptId, 'eSIM ICCID is unavailable for Auto Top-Up SMS.');
        }

        $preferredAccount = in_array((string) $simcard->provider_account, ['primary', 'legacy'], true)
            ? (string) $simcard->provider_account
            : 'legacy';

        try {
            $account = $this->provider->resolveAccountForEsim(null, $iccid, $preferredAccount);
            $dataAdded = $this->formatDataAmount($dataAddedBytes);
            $message = sprintf(
                'Your Stellar eSIM has been topped up with %s. You\'re all set. Auto Top-Up will take care of it again when your data runs low.',
                $dataAdded,
            );

            $this->provider->sendSms($iccid, $message, $account);

            SimcardAutoTopupAttempt::query()
                ->where('id', $attemptId)
                ->whereNull('sms_sent_at')
                ->update([
                    'sms_sent_at' => now(),
                    'sms_failure_reason' => null,
                ]);

            Log::info('eSIM Auto Top-Up success SMS sent.', [
                'simcard_id' => (string) $simcard->id,
                'auto_topup_attempt_id' => (string) $attempt->id,
                'topup_session_id' => (string) $session->id,
                'provider_account' => $account,
            ]);

            return 'sent';
        } catch (Throwable $exception) {
            $reason = 'Provider SMS delivery failed: ' . basename(str_replace('\\', '/', get_class($exception)));
            $this->recordSmsFailure($attemptId, $reason);

            Log::warning('Failed to send eSIM Auto Top-Up success SMS.', [
                'simcard_id' => (string) $simcard->id,
                'auto_topup_attempt_id' => (string) $attempt->id,
                'topup_session_id' => (string) $session->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return 'failed';
        }
    }

    private function recordSmsFailure(string $attemptId, string $reason): string
    {
        SimcardAutoTopupAttempt::query()
            ->where('id', $attemptId)
            ->whereNull('sms_sent_at')
            ->update([
                'sms_failure_reason' => mb_substr($reason, 0, 2000),
            ]);

        return 'failed';
    }

    private function formatDataAmount(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            $value = $bytes / 1024 / 1024 / 1024;

            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' GB';
        }

        $value = $bytes / 1024 / 1024;

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' MB';
    }

    private function resolveSimcardEmail(Simcard $simcard): ?string
    {
        if (empty($simcard->email_enc)) {
            return null;
        }

        try {
            $email = $this->crypto->decryptEmail((string) $simcard->email_enc);
        } catch (Throwable $exception) {
            Log::warning('Could not decrypt eSIM email for Auto Top-Up confirmation.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);

            return null;
        }

        $email = $this->crypto->normalizeEmail($email);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email
            : null;
    }

    private function allowanceWasReplenished(
        SimcardAutoTopup $config,
        int $totalBytes,
        int $remainingBytes,
        ?int $orderUsage,
    ): bool {
        $lastTotal = $this->nonNegativeInt($config->last_trigger_total_bytes);
        $lastRemaining = $this->nonNegativeInt($config->last_trigger_remaining_bytes);
        $lastUsage = $this->nonNegativeInt($config->last_trigger_order_usage);
        $minimumIncrease = max(64 * 1024 * 1024, (int) round(((int) $config->preferred_data_bytes) * 0.20));

        if ($lastTotal !== null && $totalBytes >= $lastTotal + $minimumIncrease) {
            return true;
        }

        if ($lastRemaining !== null && $remainingBytes >= $lastRemaining + $minimumIncrease) {
            return true;
        }

        if ($lastUsage !== null && $orderUsage !== null && $orderUsage < $lastUsage) {
            // Some providers reset usage counters before the remaining/total allowance
            // fields have caught up. Never re-arm from that signal alone while the
            // stored allowance still looks below the trigger threshold, otherwise a
            // delayed DATA_USAGE event could charge the next cycle immediately.
            $remainingPercent = $totalBytes > 0 ? ($remainingBytes / $totalBytes) * 100 : 0;

            return $remainingPercent > self::TRIGGER_PERCENT;
        }

        return false;
    }

    private function rearm(SimcardAutoTopup $config): SimcardAutoTopup
    {
        return DB::transaction(function () use ($config): SimcardAutoTopup {
            $locked = SimcardAutoTopup::query()->where('id', $config->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== self::STATE_WAITING_REARM) {
                return $locked;
            }

            if (! $locked->enabled) {
                $meta = is_array($locked->meta) ? $locked->meta : [];
                $meta['state_before_disable'] = self::STATE_WAITING_REARM;
                $locked->state = self::STATE_DISABLED;
                $locked->meta = $meta;
                $locked->save();

                return $locked->fresh();
            }

            $locked->cycle = max(1, (int) $locked->cycle) + 1;
            $locked->state = self::STATE_ARMED;
            $locked->last_rearmed_at = now();
            $locked->failure_reason = null;
            $locked->save();

            return $locked->fresh();
        });
    }

    private function uuid(string $value, string $message): string
    {
        $value = trim($value);
        if ($value === '' || ! Str::isUuid($value)) {
            throw new RuntimeException($message, 422);
        }

        return $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
