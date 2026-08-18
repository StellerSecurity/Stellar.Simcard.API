<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class UnusedEsimCancellationService
{
    private const CANCELLABLE_ESIM_STATUS = 'GOT_RESOURCE';
    private const CANCELLABLE_SMDP_STATUS = 'RELEASED';
    private const CANCELLED_STATUSES = ['CANCEL', 'CANCELED', 'CANCELLED'];

    public function __construct(
        private readonly EsimProvider $provider,
        private readonly EsimCryptoService $crypto,
    ) {}

    public function cancel(string $planId): ?array
    {
        $planId = preg_replace('/\s+/', '', $planId) ?? $planId;
        $planHash = $this->crypto->derivePlanHash($planId);

        return Cache::lock('simcard-unused-cancel:'.$planHash, 60)->block(8, function () use ($planId, $planHash): ?array {
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
            $beforeResponse = $this->provider->queryOrder($externalOrderId, $account);
            $before = $this->firstProviderEsim($beforeResponse);

            if ($before === []) {
                throw new RuntimeException('The provider did not return an eSIM profile for this order.');
            }

            if ($this->isCancelled($before)) {
                $this->markCancelled($simcard, $before);

                return [
                    'status' => 'already_cancelled',
                    'provider' => $this->safeProviderStatus($before),
                ];
            }

            $this->assertProviderStateCancellable($before);

            $esimTranNo = trim((string) ($before['esimTranNo'] ?? ''));
            if ($esimTranNo === '') {
                throw new RuntimeException('The provider did not return the eSIM transaction number required for cancellation.');
            }

            $cancelResponse = $this->provider->cancelEsim($esimTranNo, $account);
            $this->assertProviderAcceptedCancellation($cancelResponse);

            // Query the provider again instead of treating a successful HTTP call as
            // enough. If the provider is eventually consistent, the caller can retry;
            // the next attempt will safely resume from the confirmed CANCELED state.
            $afterResponse = $this->provider->queryOrder($externalOrderId, $account);
            $after = $this->firstProviderEsim($afterResponse);

            if ($after === [] || ! $this->isCancelled($after)) {
                throw new RuntimeException('The provider has not confirmed the cancelled status yet.');
            }

            $this->markCancelled($simcard, $after);

            return [
                'status' => 'cancelled',
                'provider' => [
                    'esim_status' => $this->normalizedStatus($before['esimStatus'] ?? null),
                    'smdp_status' => $this->normalizedStatus($before['smdpStatus'] ?? null),
                    'used_bytes' => $this->usedBytes($before),
                    'cancelled_status' => $this->cancelledStatus($after),
                ],
            ];
        });
    }

    private function assertLocalStateStillUnused(Simcard $simcard): void
    {
        if (
            ($simcard->order_usage !== null && (int) $simcard->order_usage > 0)
            || $simcard->activated_at !== null
            || $simcard->first_used_at !== null
        ) {
            throw new \DomainException('The eSIM has already been installed or used and cannot be cancelled automatically.');
        }
    }

    private function assertProviderStateCancellable(array $esim): void
    {
        $esimStatus = $this->normalizedStatus($esim['esimStatus'] ?? null);
        $smdpStatus = $this->normalizedStatus($esim['smdpStatus'] ?? null);
        $usage = $this->usedBytes($esim);
        $activatedAt = $this->firstNonEmpty([
            $esim['activateTime'] ?? null,
            $esim['activationTime'] ?? null,
            $esim['activatedAt'] ?? null,
        ]);

        if ($usage === null) {
            throw new \DomainException('Current eSIM usage could not be verified safely.');
        }

        if (
            $usage > 0
            || $activatedAt !== null
            || $esimStatus !== self::CANCELLABLE_ESIM_STATUS
            || $smdpStatus !== self::CANCELLABLE_SMDP_STATUS
        ) {
            throw new \DomainException('The eSIM has already been installed, used, or is no longer eligible for cancellation.');
        }
    }

    private function assertProviderAcceptedCancellation(array $response): void
    {
        $success = data_get($response, 'success');
        $errorCode = trim((string) (data_get($response, 'errorCode') ?? data_get($response, 'code') ?? ''));

        $successIsFalse = $success === false
            || (is_string($success) && in_array(strtolower(trim($success)), ['false', '0', 'no'], true))
            || (is_int($success) && $success === 0);

        if ($successIsFalse || ($errorCode !== '' && ! in_array($errorCode, ['0', '000000'], true))) {
            if (in_array($errorCode, ['200002', '200009', '200010'], true)) {
                throw new \DomainException('The provider reports that this eSIM is no longer eligible for cancellation.');
            }

            throw new RuntimeException('The provider rejected the cancellation request.');
        }
    }

    private function markCancelled(Simcard $simcard, array $provider): void
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

    private function cancelledStatus(array $esim): string
    {
        $esimStatus = $this->normalizedStatus($esim['esimStatus'] ?? null);
        if (in_array($esimStatus, self::CANCELLED_STATUSES, true)) {
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
            'cancelled_status' => $this->cancelledStatus($esim),
        ];
    }

    private function usedBytes(array $esim): ?int
    {
        $value = $esim['orderUsage'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : null;
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
