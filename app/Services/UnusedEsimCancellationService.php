<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class UnusedEsimCancellationService
{
    private const CANCELLABLE_ESIM_STATUS = 'GOT_RESOURCE';
    private const CANCELLABLE_SMDP_STATUS = 'RELEASED';
    private const CANCELLED_STATUSES = ['CANCEL', 'CANCELED', 'CANCELLED'];
    private const REVOKED_STATUSES = ['REVOKE', 'REVOKED'];
    private const REVOCABLE_ESIM_STATUSES = ['GOT_RESOURCE', 'IN_USE', 'SUSPENDED', 'USED_UP'];
    private const TRANSITIONAL_ESIM_STATUSES = ['', 'CREATE', 'PAYING', 'PAID', 'GETTING_RESOURCE'];
    private const PROVIDER_STATUS_ATTEMPTS = 4;
    private const PROVIDER_STATUS_DELAY_MICROSECONDS = 500_000;

    public function __construct(
        private readonly EsimProvider $provider,
        private readonly EsimCryptoService $crypto,
    ) {}

    public function cancel(string $planId): ?array
    {
        return $this->retire($planId, false);
    }

    /**
     * Retire a verified zero-usage eSIM before support provisions its replacement.
     * Fresh profiles use cancel (and may receive a provider credit); installed
     * profiles use revoke because eSIMAccess cannot cancel an installed profile.
     */
    public function retireForReplacement(string $planId): ?array
    {
        return $this->retire($planId, true);
    }

    /** @return array<string,mixed>|null */
    private function retire(string $planId, bool $forReplacement): ?array
    {
        $planId = preg_replace('/\s+/', '', $planId) ?? $planId;
        $planHash = $this->crypto->derivePlanHash($planId);

        return Cache::lock('simcard-unused-cancel:'.$planHash, 60)->block(8, function () use ($planId, $planHash, $forReplacement): ?array {
            $simcard = Simcard::query()->where('plan_id_hash', $planHash)->first();

            if ($simcard === null) {
                return null;
            }

            $this->assertLocalStateStillUnused($simcard);

            $externalOrderId = $this->crypto->decryptForPlan(
                $planId,
                $simcard->external_order_id_enc,
            );
            $account = $this->preferredProviderAccount($simcard);
            $before = $this->waitForProviderProfile($externalOrderId, $account);

            if ($this->isRetired($before)) {
                $this->markRetired($simcard, $before);

                return [
                    'status' => $this->isRevoked($before) ? 'already_revoked' : 'already_cancelled',
                    'provider' => $this->safeProviderStatus($before),
                ];
            }

            $retirementAction = $forReplacement
                ? $this->replacementRetirementAction($before)
                : $this->publicCancellationAction($before);

            $esimTranNo = trim((string) ($before['esimTranNo'] ?? ''));
            if ($esimTranNo === '') {
                throw new RuntimeException('The provider did not return the eSIM transaction number required to retire this profile.');
            }

            $providerResponse = $retirementAction === 'revoke'
                ? $this->provider->revokeEsim($esimTranNo, $account)
                : $this->provider->cancelEsim($esimTranNo, $account);

            // eSIMAccess returns 200002 when an already deleted device profile no
            // longer supports the revoke operation. In that one state, the old QR
            // cannot be installed again. Re-query immediately and accept it as
            // retired only while provider usage is still exactly zero and SM-DP
            // still reports DELETED. Any other lifecycle remains blocked.
            if ($retirementAction === 'revoke' && $this->statusDoesNotSupportAction($providerResponse)) {
                $confirmed = $this->firstProviderEsim($this->provider->queryOrder($externalOrderId, $account));
                if ($this->isDeletedWithZeroUsage($confirmed)) {
                    $this->markRetired($simcard, $confirmed);

                    return [
                        'status' => 'already_deleted',
                        'retirement_action' => 'revoke_unavailable_profile_deleted',
                        'provider' => $this->safeProviderStatus($confirmed),
                    ];
                }
            }
            $this->assertProviderAcceptedRetirement($providerResponse, $retirementAction);

            $after = $this->waitForProviderRetirement($externalOrderId, $account, $retirementAction);
            $this->markRetired($simcard, $after);

            return [
                'status' => $retirementAction === 'revoke' ? 'revoked' : 'cancelled',
                'retirement_action' => $retirementAction,
                'provider' => [
                    'esim_status' => $this->normalizedStatus($before['esimStatus'] ?? null),
                    'smdp_status' => $this->normalizedStatus($before['smdpStatus'] ?? null),
                    'used_bytes' => $this->usedBytes($before),
                    'cancelled_status' => $this->retiredStatus($after),
                    'retired_status' => $this->retiredStatus($after),
                ],
            ];
        });
    }

    private function assertLocalStateStillUnused(Simcard $simcard): void
    {
        if ($simcard->order_usage !== null && (int) $simcard->order_usage > 0) {
            throw new \DomainException('The eSIM has used data and cannot be cancelled automatically.');
        }
    }

    private function waitForProviderProfile(string $externalOrderId, string $account): array
    {
        $last = [];

        for ($attempt = 1; $attempt <= self::PROVIDER_STATUS_ATTEMPTS; $attempt++) {
            $response = $this->provider->queryOrder($externalOrderId, $account);
            $esim = $this->firstProviderEsim($response);

            if ($esim !== []) {
                $last = $esim;

                if ($this->isRetired($esim)) {
                    return $esim;
                }

                $classification = $this->providerEligibility($esim);

                if ($classification !== 'transitional') {
                    return $esim;
                }
            }

            if ($attempt < self::PROVIDER_STATUS_ATTEMPTS) {
                usleep(self::PROVIDER_STATUS_DELAY_MICROSECONDS);
            }
        }

        if ($last !== []) {
            $status = $this->safeProviderStatus($last);

            Log::info('eSIM cancellation waiting for provider state to settle.', [
                'external_order_id' => $externalOrderId,
                'provider_account' => $account,
                'esim_status' => $status['esim_status'],
                'smdp_status' => $status['smdp_status'],
                'used_bytes' => $status['used_bytes'],
            ]);

            throw new RuntimeException('The eSIM is still being prepared by the provider. Please try the cancellation again shortly.');
        }

        throw new RuntimeException('The provider did not return an eSIM profile for this order yet.');
    }

    private function waitForProviderRetirement(string $externalOrderId, string $account, string $action): array
    {
        for ($attempt = 1; $attempt <= self::PROVIDER_STATUS_ATTEMPTS; $attempt++) {
            $response = $this->provider->queryOrder($externalOrderId, $account);
            $esim = $this->firstProviderEsim($response);

            if ($esim !== [] && $this->isRetired($esim)) {
                return $esim;
            }

            if ($attempt < self::PROVIDER_STATUS_ATTEMPTS) {
                usleep(self::PROVIDER_STATUS_DELAY_MICROSECONDS);
            }
        }

        throw new RuntimeException(
            'The provider has not confirmed the '.($action === 'revoke' ? 'revoked' : 'cancelled').' status yet.'
        );
    }

    private function publicCancellationAction(array $esim): string
    {
        $classification = $this->providerEligibility($esim);

        if ($classification === 'cancel') {
            return 'cancel';
        }

        if ($classification === 'transitional') {
            throw new RuntimeException('The eSIM is still being prepared by the provider. Please try the cancellation again shortly.');
        }

        if ($classification === 'revoke') {
            throw new \DomainException('The eSIM is installed and cannot be cancelled automatically.');
        }

        $this->throwUsageOrLifecycleError($esim, 'cancellation');
    }

    private function replacementRetirementAction(array $esim): string
    {
        $usage = $this->usedBytes($esim);
        if ($usage === null) {
            throw new RuntimeException('Replacement blocked because live provider usage is unknown.');
        }
        if ($usage > 0) {
            throw new \DomainException('Replacement blocked because the provider reports data usage on this eSIM.');
        }

        $classification = $this->providerEligibility($esim);
        if ($classification === 'cancel' || $classification === 'revoke') {
            return $classification;
        }
        if ($classification === 'transitional') {
            throw new RuntimeException('The eSIM is still being prepared by the provider. Please try the replacement again shortly.');
        }

        $this->throwUsageOrLifecycleError($esim, 'replacement');
    }

    private function throwUsageOrLifecycleError(array $esim, string $operation): never
    {
        $esimStatus = $this->normalizedStatus($esim['esimStatus'] ?? null);
        $smdpStatus = $this->normalizedStatus($esim['smdpStatus'] ?? null);
        $usage = $this->usedBytes($esim);

        Log::info('eSIM retirement rejected by provider state.', [
            'operation' => $operation,
            'esim_status' => $esimStatus,
            'smdp_status' => $smdpStatus,
            'used_bytes' => $usage,
            'has_activation_time' => $this->activationTime($esim) !== null,
            'has_eid' => $this->eid($esim) !== null,
        ]);

        if ($usage !== null && $usage > 0) {
            throw new \DomainException('The provider reports data usage on this eSIM, so it cannot be cancelled automatically.');
        }

        throw new \DomainException(
            'The provider reports that this eSIM is not eligible for '.$operation.'. Current status: '
            .($smdpStatus !== '' ? $smdpStatus : 'UNKNOWN')
            .' / '
            .($esimStatus !== '' ? $esimStatus : 'UNKNOWN')
            .'.'
        );
    }

    private function providerEligibility(array $esim): string
    {
        $esimStatus = $this->normalizedStatus($esim['esimStatus'] ?? null);
        $smdpStatus = $this->normalizedStatus($esim['smdpStatus'] ?? null);
        $usage = $this->usedBytes($esim);

        if ($this->isRetired($esim)) {
            return 'retired';
        }

        if ($usage !== null && $usage > 0) {
            return 'blocked';
        }

        if (
            $esimStatus === self::CANCELLABLE_ESIM_STATUS
            && $smdpStatus === self::CANCELLABLE_SMDP_STATUS
        ) {
            return 'cancel';
        }

        // eSIMAccess cannot cancel an installed profile, even when it has consumed
        // exactly 0 bytes. Support replacements permanently revoke that old profile
        // before provisioning the same purchased plan again.
        if ($usage === 0 && in_array($esimStatus, self::REVOCABLE_ESIM_STATUSES, true)) {
            return 'revoke';
        }

        if (in_array($esimStatus, self::TRANSITIONAL_ESIM_STATUSES, true)) {
            return 'transitional';
        }

        return 'transitional';
    }

    private function assertProviderAcceptedRetirement(array $response, string $action): void
    {
        $success = data_get($response, 'success');
        $errorCode = trim((string) (data_get($response, 'errorCode') ?? data_get($response, 'code') ?? ''));

        $successIsFalse = $success === false
            || (is_string($success) && in_array(strtolower(trim($success)), ['false', '0', 'no'], true))
            || (is_int($success) && $success === 0);

        if ($successIsFalse || ($errorCode !== '' && ! in_array($errorCode, ['0', '000000'], true))) {
            if (in_array($errorCode, ['200002', '200009', '200010'], true)) {
                throw new \DomainException('The provider reports that this eSIM is no longer eligible for '.$action.'.');
            }

            throw new RuntimeException('The provider rejected the '.$action.' request.');
        }
    }

    private function statusDoesNotSupportAction(array $response): bool
    {
        $success = data_get($response, 'success');
        $errorCode = trim((string) (data_get($response, 'errorCode') ?? data_get($response, 'code') ?? ''));
        $failed = $success === false
            || (is_string($success) && in_array(strtolower(trim($success)), ['false', '0', 'no'], true))
            || (is_int($success) && $success === 0);

        return $failed && $errorCode === '200002';
    }

    private function isDeletedWithZeroUsage(array $esim): bool
    {
        return $esim !== []
            && $this->normalizedStatus($esim['smdpStatus'] ?? null) === 'DELETED'
            && $this->usedBytes($esim) === 0;
    }

    private function markRetired(Simcard $simcard, array $provider): void
    {
        DB::transaction(function () use ($simcard, $provider): void {
            $locked = Simcard::query()
                ->whereKey($simcard->id)
                ->lockForUpdate()
                ->firstOrFail();

            $usage = $this->usedBytes($provider);
            $esimStatus = $this->normalizedStatus($provider['esimStatus'] ?? null);
            $smdpStatus = $this->normalizedStatus($provider['smdpStatus'] ?? null);

            $attributes = [
                'state' => 'cancelled',
                'esim_status' => $esimStatus !== '' ? $esimStatus : 'CANCELED',
                'smdp_status' => $smdpStatus !== '' ? $smdpStatus : $locked->smdp_status,
                'remaining_volume' => 0,
            ];

            if ($usage !== null) {
                $attributes['order_usage'] = $usage;
            }

            $locked->forceFill($attributes)->save();

            if (Schema::hasTable('simcard_auto_topups')) {
                SimcardAutoTopup::query()
                    ->where('simcard_id', $locked->id)
                    ->where('enabled', true)
                    ->update([
                        'enabled' => false,
                        'state' => 'CANCELLED',
                        'failure_reason' => null,
                        'updated_at' => now(),
                    ]);
            }
        }, 3);
    }

    private function preferredProviderAccount(Simcard $simcard): string
    {
        return in_array($simcard->provider_account, ['primary', 'legacy'], true)
            ? $simcard->provider_account
            : 'legacy';
    }

    private function firstProviderEsim(array $provider): array
    {
        foreach ([
            data_get($provider, 'obj.esimList.0'),
            data_get($provider, 'data.obj.esimList.0'),
            data_get($provider, 'data.esimList.0'),
            data_get($provider, 'esimList.0'),
        ] as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function isCancelled(array $esim): bool
    {
        return in_array($this->normalizedStatus($esim['esimStatus'] ?? null), self::CANCELLED_STATUSES, true)
            || in_array($this->normalizedStatus($esim['smdpStatus'] ?? null), self::CANCELLED_STATUSES, true);
    }

    private function isRevoked(array $esim): bool
    {
        return in_array($this->normalizedStatus($esim['esimStatus'] ?? null), self::REVOKED_STATUSES, true)
            || in_array($this->normalizedStatus($esim['smdpStatus'] ?? null), self::REVOKED_STATUSES, true);
    }

    private function isRetired(array $esim): bool
    {
        return $this->isCancelled($esim) || $this->isRevoked($esim);
    }

    private function retiredStatus(array $esim): string
    {
        $esimStatus = $this->normalizedStatus($esim['esimStatus'] ?? null);
        if (in_array($esimStatus, [...self::CANCELLED_STATUSES, ...self::REVOKED_STATUSES], true)) {
            return $esimStatus;
        }

        return $this->normalizedStatus($esim['smdpStatus'] ?? null);
    }

    private function safeProviderStatus(array $esim): array
    {
        return [
            'esim_status' => $this->normalizedStatus($esim['esimStatus'] ?? null),
            'smdp_status' => $this->normalizedStatus($esim['smdpStatus'] ?? null),
            'used_bytes' => $this->usedBytes($esim),
            'cancelled_status' => $this->retiredStatus($esim),
            'retired_status' => $this->retiredStatus($esim),
        ];
    }

    private function usedBytes(array $esim): ?int
    {
        $value = $esim['orderUsage'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function activationTime(array $esim): ?string
    {
        return $this->firstNonEmpty([
            $esim['activateTime'] ?? null,
            $esim['activationTime'] ?? null,
            $esim['activatedAt'] ?? null,
        ]);
    }

    private function eid(array $esim): ?string
    {
        return $this->firstNonEmpty([$esim['eid'] ?? null]);
    }

    private function normalizedStatus(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
