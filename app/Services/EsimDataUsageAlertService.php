<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Models\SimcardDataUsageAlertState;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use StellarSecurity\Notifications\DTO\NotificationEvent;
use StellarSecurity\Notifications\Facades\Notification;
use Throwable;

class EsimDataUsageAlertService
{
    private const PROVIDER = 'esimaccess';
    private const THRESHOLD_PERCENT = 35;
    private const CHECK_INTERVAL_MINUTES = 60;
    private const DELIVERY_RETRY_COOLDOWN_MINUTES = 10;

    private const STATE_ARMED = 'ARMED';
    private const STATE_NOTIFIED = 'NOTIFIED';

    private const DELIVERY_PENDING = 'PENDING';
    private const DELIVERY_ATTEMPTING = 'ATTEMPTING';
    private const DELIVERY_SENT = 'SENT';
    private const DELIVERY_SKIPPED = 'SKIPPED';
    private const DELIVERY_FAILED = 'FAILED';

    public function __construct(
        private readonly EsimCryptoService $crypto,
        private readonly EsimProvider $provider,
        private readonly SimcardActionLinkService $actionLinks,
    ) {}

    /**
     * Refresh usage for active eSIMs that do not currently have an active Auto Top-Up
     * configuration and notify them on the first observed crossing to <= 50% remaining.
     *
     * @return array<string,int>
     */
    public function processPending(int $limit = 100, ?string $onlySimcardId = null, bool $force = false): array
    {
        $summary = [
            'processed' => 0,
            'refreshed' => 0,
            'triggered' => 0,
            'rearmed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'sms_sent' => 0,
            'email_sent' => 0,
        ];

        $query = Simcard::query()
            ->select('simcards.*')
            ->leftJoin('simcard_data_usage_alert_states as usage_alert_state', function (JoinClause $join): void {
                $join
                    ->on('usage_alert_state.simcard_id', '=', 'simcards.id')
                    ->where('usage_alert_state.threshold_percent', '=', self::THRESHOLD_PERCENT);
            })
            ->where('simcards.provider', self::PROVIDER)
            ->where('simcards.esim_status', 'IN_USE')
            ->whereNotExists(function ($autoTopupQuery): void {
                $autoTopupQuery
                    ->selectRaw('1')
                    ->from('simcard_auto_topups')
                    ->whereColumn('simcard_auto_topups.simcard_id', 'simcards.id')
                    ->where('simcard_auto_topups.enabled', true)
                    ->where('simcard_auto_topups.state', '!=', 'PAUSED');
            });

        $onlySimcardId = trim((string) $onlySimcardId);
        if ($onlySimcardId !== '') {
            $query->where('simcards.id', $onlySimcardId);
        }

        if (! $force) {
            $cutoff = now()->subMinutes(self::CHECK_INTERVAL_MINUTES);
            $query->where(function ($staleQuery) use ($cutoff): void {
                $staleQuery
                    ->whereNull('usage_alert_state.last_checked_at')
                    ->orWhere('usage_alert_state.last_checked_at', '<=', $cutoff);
            });
        }

        $simcards = $query
            ->orderBy('usage_alert_state.last_checked_at')
            ->orderBy('simcards.id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($simcards as $simcard) {
            $summary['processed']++;

            try {
                if ($this->hasActiveAutoTopup((string) $simcard->id)) {
                    $summary['skipped']++;
                    continue;
                }

                $state = $this->ensureState((string) $simcard->id);
                $refresh = $this->refreshUsageFromProvider($simcard);

                if (($refresh['status'] ?? '') !== 'refreshed') {
                    $this->recordFailedCheck(
                        (string) $state->id,
                        (string) ($refresh['reason'] ?? 'provider_usage_refresh_failed'),
                    );

                    if (($refresh['status'] ?? '') === 'failed') {
                        $summary['failed']++;
                    } else {
                        $summary['skipped']++;
                    }

                    continue;
                }

                $summary['refreshed']++;

                if ($this->hasActiveAutoTopup((string) $simcard->id)) {
                    $this->recordFailedCheck((string) $state->id, 'auto_topup_became_active');
                    $summary['skipped']++;
                    continue;
                }

                $decision = $this->recordSnapshotAndDecide(
                    (string) $state->id,
                    (int) $refresh['total_bytes'],
                    (int) $refresh['remaining_bytes'],
                    isset($refresh['order_usage']) ? (int) $refresh['order_usage'] : null,
                    (float) $refresh['remaining_percent'],
                );

                if (($decision['rearmed'] ?? false) === true) {
                    $summary['rearmed']++;
                }

                if (($decision['triggered'] ?? false) === true) {
                    $summary['triggered']++;
                }

                if (($decision['should_deliver'] ?? false) !== true) {
                    $summary['skipped']++;
                    continue;
                }

                $freshSimcard = Simcard::query()->where('id', $simcard->id)->first();
                if ($freshSimcard === null || $this->hasActiveAutoTopup((string) $simcard->id)) {
                    $summary['skipped']++;
                    continue;
                }

                $deliveryContext = [
                    'iccid' => (string) $refresh['iccid'],
                    'provider_account' => (string) $refresh['provider_account'],
                    'package_label' => $refresh['package_label'] ?? null,
                    'total_bytes' => (int) $refresh['total_bytes'],
                    'remaining_bytes' => (int) $refresh['remaining_bytes'],
                    'order_usage' => isset($refresh['order_usage']) ? (int) $refresh['order_usage'] : null,
                    'remaining_percent' => (float) $refresh['remaining_percent'],
                    'cycle' => (int) $decision['cycle'],
                ];

                $smsResult = $this->sendSmsIfNeeded((string) $state->id, $freshSimcard, $deliveryContext);
                if ($smsResult === self::DELIVERY_SENT) {
                    $summary['sms_sent']++;
                }

                $emailResult = $this->sendEmailIfNeeded((string) $state->id, $freshSimcard, $deliveryContext);
                if ($emailResult === self::DELIVERY_SENT) {
                    $summary['email_sent']++;
                }
            } catch (Throwable $exception) {
                $summary['failed']++;

                Log::warning('Scheduled eSIM 50% data alert processing failed.', [
                    'simcard_id' => (string) $simcard->id,
                    'exception' => $this->safeExceptionName($exception),
                ]);
            }
        }

        return $summary;
    }

    private function ensureState(string $simcardId): SimcardDataUsageAlertState
    {
        return DB::transaction(function () use ($simcardId): SimcardDataUsageAlertState {
            Simcard::query()
                ->where('id', $simcardId)
                ->lockForUpdate()
                ->firstOrFail();

            $state = SimcardDataUsageAlertState::query()
                ->where('simcard_id', $simcardId)
                ->where('threshold_percent', self::THRESHOLD_PERCENT)
                ->lockForUpdate()
                ->first();

            if ($state !== null) {
                return $state;
            }

            $state = new SimcardDataUsageAlertState();
            $state->id = (string) Str::uuid();
            $state->simcard_id = $simcardId;
            $state->threshold_percent = self::THRESHOLD_PERCENT;
            $state->state = self::STATE_ARMED;
            $state->cycle = 1;
            $state->save();

            return $state;
        });
    }

    /**
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

            $orderNo = null;
            if (! empty($simcard->external_order_id_enc)) {
                $decryptedOrderNo = trim($this->crypto->decryptSensitiveValue((string) $simcard->external_order_id_enc));
                $orderNo = $decryptedOrderNo !== '' ? $decryptedOrderNo : null;
            }

            $preferredAccount = $this->preferredProviderAccount($simcard);
            $account = $preferredAccount;
            $response = $this->provider->queryEsim($orderNo, $iccid, $account);
            $providerSim = $this->extractProviderSim($response);

            if (! is_array($providerSim)) {
                $account = $this->provider->resolveAccountForEsim($orderNo, $iccid, $preferredAccount);
                $response = $this->provider->queryEsim($orderNo, $iccid, $account);
                $providerSim = $this->extractProviderSim($response);
            }

            if (! is_array($providerSim)) {
                return ['status' => 'skipped', 'reason' => 'provider_usage_not_ready'];
            }

            $returnedIccid = trim((string) ($providerSim['iccid'] ?? ''));
            if ($returnedIccid !== '' && ! hash_equals($iccid, $returnedIccid)) {
                Log::warning('eSIM 50% data alert provider response ICCID mismatch.', [
                    'simcard_id' => (string) $simcard->id,
                    'provider_account' => $account,
                ]);

                return ['status' => 'failed', 'reason' => 'provider_iccid_mismatch'];
            }

            $returnedOrderNo = trim((string) ($providerSim['orderNo'] ?? ''));
            if ($orderNo !== null && $returnedOrderNo !== '' && ! hash_equals($orderNo, $returnedOrderNo)) {
                Log::warning('eSIM 50% data alert provider response order mismatch.', [
                    'simcard_id' => (string) $simcard->id,
                    'provider_account' => $account,
                ]);

                return ['status' => 'failed', 'reason' => 'provider_order_mismatch'];
            }

            $providerStatus = strtoupper(trim((string) ($providerSim['esimStatus'] ?? '')));
            $smdpStatus = trim((string) ($providerSim['smdpStatus'] ?? ''));
            $totalBytes = $this->positiveInt($providerSim['totalVolume'] ?? null);
            $orderUsage = $this->nonNegativeInt($providerSim['orderUsage'] ?? null);
            $remainingBytes = $this->nonNegativeInt($providerSim['remain'] ?? null);

            if ($remainingBytes === null && $totalBytes !== null && $orderUsage !== null) {
                $remainingBytes = max(0, $totalBytes - $orderUsage);
            }

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

            if ($providerStatus !== '' && $providerStatus !== 'IN_USE') {
                return ['status' => 'skipped', 'reason' => 'esim_not_in_use'];
            }

            if ($totalBytes === null || $remainingBytes === null || $totalBytes <= 0) {
                return ['status' => 'skipped', 'reason' => 'provider_usage_not_ready'];
            }

            $remainingPercent = max(0.0, min(100.0, ($remainingBytes / $totalBytes) * 100));

            return [
                'status' => 'refreshed',
                'iccid' => $iccid,
                'provider_account' => $account,
                'package_label' => $this->extractPackageLabel($providerSim),
                'total_bytes' => $totalBytes,
                'order_usage' => $orderUsage,
                'remaining_bytes' => $remainingBytes,
                'remaining_percent' => round($remainingPercent, 2),
            ];
        } catch (Throwable $exception) {
            Log::warning('eSIM 50% data alert provider usage refresh failed.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => $this->safeExceptionName($exception),
            ]);

            return ['status' => 'failed', 'reason' => 'provider_usage_refresh_failed'];
        }
    }

    private function recordFailedCheck(string $stateId, string $reason): void
    {
        SimcardDataUsageAlertState::query()
            ->where('id', $stateId)
            ->update([
                'last_checked_at' => now(),
                'last_check_failure_reason' => mb_substr(trim($reason), 0, 2000),
            ]);
    }

    /**
     * @return array{triggered:bool,rearmed:bool,should_deliver:bool,cycle:int}
     */
    private function recordSnapshotAndDecide(
        string $stateId,
        int $totalBytes,
        int $remainingBytes,
        ?int $orderUsage,
        float $remainingPercent,
    ): array {
        return DB::transaction(function () use (
            $stateId,
            $totalBytes,
            $remainingBytes,
            $orderUsage,
            $remainingPercent,
        ): array {
            $state = SimcardDataUsageAlertState::query()
                ->where('id', $stateId)
                ->lockForUpdate()
                ->firstOrFail();

            $state->last_checked_at = now();
            $state->last_check_failure_reason = null;
            $state->last_observed_total_bytes = $totalBytes;
            $state->last_observed_remaining_bytes = $remainingBytes;
            $state->last_observed_order_usage = $orderUsage;
            $state->last_observed_remaining_percent = round($remainingPercent, 2);

            $rearmed = false;
            $triggered = false;

            if ($state->state === self::STATE_NOTIFIED) {
                // Re-arm only after the provider has explicitly observed the allowance
                // above the threshold. A top-up that still leaves the eSIM at or below
                // 50% must not create a new cycle and immediately send another alert.
                if ($remainingPercent > self::THRESHOLD_PERCENT) {
                    $this->rearmState($state);
                    $rearmed = true;
                }
            }

            if ($state->state === self::STATE_ARMED && $remainingPercent <= self::THRESHOLD_PERCENT) {
                $state->state = self::STATE_NOTIFIED;
                $state->notified_at = now();
                $state->trigger_total_bytes = $totalBytes;
                $state->trigger_remaining_bytes = $remainingBytes;
                $state->trigger_order_usage = $orderUsage;
                $state->trigger_remaining_percent = round($remainingPercent, 2);
                $state->sms_status = self::DELIVERY_PENDING;
                $state->email_status = self::DELIVERY_PENDING;
                $triggered = true;
            }

            $shouldDeliver = $state->state === self::STATE_NOTIFIED
                && (
                    ! in_array($state->sms_status, [self::DELIVERY_SENT, self::DELIVERY_SKIPPED], true)
                    || ! in_array($state->email_status, [self::DELIVERY_SENT, self::DELIVERY_SKIPPED], true)
                );

            $state->save();

            return [
                'triggered' => $triggered,
                'rearmed' => $rearmed,
                'should_deliver' => $shouldDeliver,
                'cycle' => max(1, (int) $state->cycle),
            ];
        });
    }

    private function rearmState(SimcardDataUsageAlertState $state): void
    {
        $state->state = self::STATE_ARMED;
        $state->cycle = max(1, (int) $state->cycle) + 1;
        $state->last_rearmed_at = now();
        $state->notified_at = null;
        $state->trigger_total_bytes = null;
        $state->trigger_remaining_bytes = null;
        $state->trigger_order_usage = null;
        $state->trigger_remaining_percent = null;
        $state->sms_status = null;
        $state->sms_attempted_at = null;
        $state->sms_sent_at = null;
        $state->sms_failure_reason = null;
        $state->email_status = null;
        $state->email_attempted_at = null;
        $state->email_sent_at = null;
        $state->email_failure_reason = null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function sendSmsIfNeeded(string $stateId, Simcard $simcard, array $context): string
    {
        if ($this->hasActiveAutoTopup((string) $simcard->id)) {
            return self::DELIVERY_SKIPPED;
        }

        if (! $this->claimDelivery($stateId, 'sms')) {
            return self::DELIVERY_SKIPPED;
        }

        $iccid = trim((string) ($context['iccid'] ?? ''));
        if ($iccid === '') {
            return $this->recordDeliveryFailure($stateId, 'sms', 'eSIM ICCID is unavailable for 50% data alert SMS.');
        }

        $account = in_array((string) ($context['provider_account'] ?? ''), ['primary', 'legacy'], true)
            ? (string) $context['provider_account']
            : $this->preferredProviderAccount($simcard);

        $planPhrase = $this->safePlanPhrase($context['package_label'] ?? null);

        try {
            $topupUrl = $this->actionLinks->createTopupUrl($simcard, 'data_50_percent');
            $message = 'Your Stellar eSIM' . $planPhrase . ' is running low on data. Top up anytime here: ' . $topupUrl;

            $this->provider->sendSms($iccid, $message, $account);
            $this->recordDeliverySuccess($stateId, 'sms');

            Log::info('eSIM 50% data alert SMS sent.', [
                'simcard_id' => (string) $simcard->id,
                'threshold_percent' => self::THRESHOLD_PERCENT,
                'cycle' => (int) ($context['cycle'] ?? 1),
                'provider_account' => $account,
            ]);

            return self::DELIVERY_SENT;
        } catch (Throwable $exception) {
            Log::warning('Failed to send eSIM 50% data alert SMS.', [
                'simcard_id' => (string) $simcard->id,
                'threshold_percent' => self::THRESHOLD_PERCENT,
                'exception' => $this->safeExceptionName($exception),
            ]);

            return $this->recordDeliveryFailure(
                $stateId,
                'sms',
                'Provider SMS delivery failed: ' . $this->safeExceptionName($exception),
            );
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function sendEmailIfNeeded(string $stateId, Simcard $simcard, array $context): string
    {
        if ($this->hasActiveAutoTopup((string) $simcard->id)) {
            return self::DELIVERY_SKIPPED;
        }

        if (! $this->claimDelivery($stateId, 'email')) {
            return self::DELIVERY_SKIPPED;
        }

        $email = $this->resolveSimcardEmail($simcard);
        if ($email === null) {
            $this->recordDeliverySkipped($stateId, 'email', 'Customer email is unavailable for 50% data alert.');

            return self::DELIVERY_SKIPPED;
        }

        try {
            $topupUrl = $this->actionLinks->createTopupUrl($simcard, 'data_50_percent');
            $packageLabel = $this->sanitizePackageLabel($context['package_label'] ?? null);
            $cycle = max(1, (int) ($context['cycle'] ?? 1));
            $idempotencyKey = sprintf(
                'esim_data_50_email_%s_cycle_%d',
                (string) $simcard->id,
                $cycle,
            );

            $payload = array_filter([
                'app_name' => 'Stellar Data',
                'simcard_id' => (string) $simcard->id,
                'package_label' => $packageLabel,
                'headline' => 'Your eSIM is running low on data',
                'intro' => 'Your mobile data is running low. Top up anytime to stay connected.',
                'topup_url' => $topupUrl,
                'manage_url' => $topupUrl,
                'support_url' => 'https://stellarsecurity.com/contact-us',
                'support_email' => 'info@stellarsecurity.com',
                'threshold_percent' => self::THRESHOLD_PERCENT,
                'remaining_percent' => round((float) ($context['remaining_percent'] ?? 0), 2),
                'remaining_bytes' => $context['remaining_bytes'] ?? null,
                'total_bytes' => $context['total_bytes'] ?? null,
                'used_bytes' => $context['order_usage'] ?? null,
            ], static fn ($value) => $value !== null && $value !== '');

            Notification::send(
                NotificationEvent::make('esim_low_data')
                    ->product('stellar-data')
                    ->email($email)
                    ->payload($payload)
                    ->idempotencyKey($idempotencyKey)
            );

            $this->recordDeliverySuccess($stateId, 'email');

            Log::info('eSIM 50% data alert email sent.', [
                'simcard_id' => (string) $simcard->id,
                'threshold_percent' => self::THRESHOLD_PERCENT,
                'cycle' => $cycle,
                'idempotency_key' => $idempotencyKey,
            ]);

            return self::DELIVERY_SENT;
        } catch (Throwable $exception) {
            Log::warning('Failed to send eSIM 50% data alert email.', [
                'simcard_id' => (string) $simcard->id,
                'threshold_percent' => self::THRESHOLD_PERCENT,
                'exception' => $this->safeExceptionName($exception),
            ]);

            return $this->recordDeliveryFailure(
                $stateId,
                'email',
                'Notification email failed: ' . $this->safeExceptionName($exception),
            );
        }
    }

    private function claimDelivery(string $stateId, string $channel): bool
    {
        return DB::transaction(function () use ($stateId, $channel): bool {
            $state = SimcardDataUsageAlertState::query()
                ->where('id', $stateId)
                ->lockForUpdate()
                ->first();

            if ($state === null || $state->state !== self::STATE_NOTIFIED) {
                return false;
            }

            $statusField = $channel . '_status';
            $attemptedAtField = $channel . '_attempted_at';
            $failureField = $channel . '_failure_reason';
            $status = (string) ($state->{$statusField} ?? '');
            $attemptedAt = $state->{$attemptedAtField};

            if (in_array($status, [self::DELIVERY_SENT, self::DELIVERY_SKIPPED], true)) {
                return false;
            }

            if (
                in_array($status, [self::DELIVERY_ATTEMPTING, self::DELIVERY_FAILED], true)
                && $attemptedAt !== null
                && $attemptedAt->gt(now()->subMinutes(self::DELIVERY_RETRY_COOLDOWN_MINUTES))
            ) {
                return false;
            }

            $state->{$statusField} = self::DELIVERY_ATTEMPTING;
            $state->{$attemptedAtField} = now();
            $state->{$failureField} = null;
            $state->save();

            return true;
        });
    }

    private function recordDeliverySuccess(string $stateId, string $channel): void
    {
        $statusField = $channel . '_status';
        $sentAtField = $channel . '_sent_at';
        $failureField = $channel . '_failure_reason';

        SimcardDataUsageAlertState::query()
            ->where('id', $stateId)
            ->update([
                $statusField => self::DELIVERY_SENT,
                $sentAtField => now(),
                $failureField => null,
            ]);
    }

    private function recordDeliverySkipped(string $stateId, string $channel, string $reason): void
    {
        $statusField = $channel . '_status';
        $failureField = $channel . '_failure_reason';

        SimcardDataUsageAlertState::query()
            ->where('id', $stateId)
            ->update([
                $statusField => self::DELIVERY_SKIPPED,
                $failureField => mb_substr($reason, 0, 2000),
            ]);
    }

    private function recordDeliveryFailure(string $stateId, string $channel, string $reason): string
    {
        $statusField = $channel . '_status';
        $failureField = $channel . '_failure_reason';

        SimcardDataUsageAlertState::query()
            ->where('id', $stateId)
            ->update([
                $statusField => self::DELIVERY_FAILED,
                $failureField => mb_substr($reason, 0, 2000),
            ]);

        return self::DELIVERY_FAILED;
    }

    private function hasActiveAutoTopup(string $simcardId): bool
    {
        return SimcardAutoTopup::query()
            ->where('simcard_id', $simcardId)
            ->where('enabled', true)
            ->where('state', '!=', 'PAUSED')
            ->exists();
    }

    private function resolveSimcardEmail(Simcard $simcard): ?string
    {
        if (empty($simcard->email_enc)) {
            return null;
        }

        try {
            $email = $this->crypto->decryptEmail((string) $simcard->email_enc);
        } catch (Throwable $exception) {
            Log::warning('Could not decrypt simcard email for 50% data alert.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => $this->safeExceptionName($exception),
            ]);

            return null;
        }

        $email = $this->crypto->normalizeEmail($email);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email
            : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractProviderSim(array $response): ?array
    {
        foreach (['obj.esimList.0', 'data.obj.esimList.0', 'data.esimList.0', 'esimList.0'] as $path) {
            $candidate = data_get($response, $path);
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractPackageLabel(array $providerSim): ?string
    {
        $package = data_get($providerSim, 'packageList.0');
        if (! is_array($package)) {
            return null;
        }

        foreach (['packageName', 'name', 'slug', 'locationCode'] as $key) {
            $label = $this->sanitizePackageLabel($package[$key] ?? null);
            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    private function safePlanPhrase(mixed $label): string
    {
        $label = $this->sanitizePackageLabel($label);

        return $label === null ? '' : ' for ' . $label;
    }

    private function sanitizePackageLabel(mixed $label): ?string
    {
        if (! is_scalar($label)) {
            return null;
        }

        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }

        $label = preg_replace('/[^\p{L}\p{N} .,+\-\/()]/u', '', $label) ?? '';
        $label = preg_replace('/\s+/u', ' ', $label) ?? '';
        $label = trim($label);

        return $label === '' ? null : Str::limit($label, 60, '');
    }

    private function preferredProviderAccount(Simcard $simcard): string
    {
        return in_array((string) $simcard->provider_account, ['primary', 'legacy'], true)
            ? (string) $simcard->provider_account
            : 'legacy';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value >= 0 ? $value : null;
    }

    private function safeExceptionName(Throwable $exception): string
    {
        return basename(str_replace('\\', '/', get_class($exception)));
    }
}
