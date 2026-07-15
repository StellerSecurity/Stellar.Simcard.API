<?php

namespace App\Services;

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SimcardService
{
    public function __construct(
        private readonly EsimProvider $provider,
        private readonly EsimCryptoService $crypto,
    ) {}

    /** Fetch plan list from provider */
    public function listPlans(array $filters = []): array
    {
        return $this->provider->listPlans($filters);
    }

    /** Create eSIM order using a client-side generated plan_id (idempotent per plan_id_hash) */
    public function orderEsim(
        int $userId,
        ?string $accountRef,
        string $packageCode,
        string $planId,
        ?string $email = null,
        ?string $emailSource = 'order',
        ?string $commerceOrderId = null,
        ?string $commerceOrderItemId = null,
        ?int $commerceUnit = null,
        ?string $idempotencyKey = null
    ): Simcard {

        $planId = preg_replace('/\s+/', '', (string) $planId);
        $planIdHash = $this->crypto->derivePlanHash($planId);
        $commerceOrderId = $this->normalizeNullableIdentifier($commerceOrderId);
        $commerceOrderItemId = $this->normalizeNullableIdentifier($commerceOrderItemId);
        $commerceUnit = $commerceUnit !== null && $commerceUnit > 0 ? $commerceUnit : null;
        $idempotencyKey = $this->normalizeNullableIdentifier($idempotencyKey);

        return DB::transaction(function () use ($planIdHash, $userId, $accountRef, $packageCode, $planId, $email, $emailSource, $commerceOrderId, $commerceOrderItemId, $commerceUnit, $idempotencyKey) {
            // If Commerce retries the same paid order item, do not create a second provider order.
            $existing = $this->findExistingSimcardForOrderRequest(
                planIdHash: $planIdHash,
                commerceOrderId: $commerceOrderId,
                commerceOrderItemId: $commerceOrderItemId,
                commerceUnit: $commerceUnit,
                idempotencyKey: $idempotencyKey,
            );

            if ($existing) {
                $this->storeEmailOnSimcard($existing, $email, $emailSource);
                $this->attachCommerceIdempotencyMetadata($existing, $commerceOrderId, $commerceOrderItemId, $commerceUnit, $idempotencyKey);

                return $existing;
            }

            $order = $this->provider->createOrder($packageCode);

            $externalOrderIdEnc = $this->crypto->encryptForPlan(
                $planId,
                $order->externalOrderId
            );

            $externalOrderIdHash = $this->crypto->deriveExternalOrderHash($order->externalOrderId);

            $simcard = Simcard::create([
                'id'                    => (string) Str::uuid(),
                'plan_id_hash'          => $planIdHash,
                'provider'              => 'esimaccess',
                'package_code'          => $packageCode,
                'external_order_id_enc'  => $externalOrderIdEnc,
                'external_order_id_hash' => $externalOrderIdHash,
                'state'                  => 'pending',
                'user_id'                => $userId,
                'commerce_order_id'      => $commerceOrderId,
                'commerce_order_item_id' => $commerceOrderItemId,
                'commerce_unit'          => $commerceUnit,
                'idempotency_key'        => $idempotencyKey,
                'purchased_on'           => now()->toDateString(),
            ]);

            $this->storeEmailOnSimcard($simcard, $email, $emailSource);

            return $simcard;
        });
    }

    /** Order and return install payload (AC) when available */
    public function orderAndGetInstallInfo(
        int $userId,
        ?string $accountRef,
        string $packageCode,
        string $planId,
        ?string $email = null,
        ?string $emailSource = 'order',
        ?string $commerceOrderId = null,
        ?string $commerceOrderItemId = null,
        ?int $commerceUnit = null,
        ?string $idempotencyKey = null
    ): array {
        $simcard = $this->orderEsim(
            userId: $userId,
            accountRef: $accountRef,
            packageCode: $packageCode,
            planId: $planId,
            email: $email,
            emailSource: $emailSource,
            commerceOrderId: $commerceOrderId,
            commerceOrderItemId: $commerceOrderItemId,
            commerceUnit: $commerceUnit,
            idempotencyKey: $idempotencyKey,
        );

        $install = $this->fetchInstallInfoWithRetry($planId);

        return [
            'simcard' => $simcard,
            'install' => $install,
        ];
    }

    /** Query provider for usage/status for a given plan_id */
    public function queryStatusByPlanId(string $planId): ?array
    {

        $planIdHash = $this->crypto->derivePlanHash($planId);

        $simcard = Simcard::where('plan_id_hash', $planIdHash)->first();

        if (!$simcard) {
            return null;
        }

        $externalOrderId = $this->crypto->decryptForPlan(
            $planId,
            $simcard->external_order_id_enc
        );


        $provider = $this->provider->queryOrder($externalOrderId);

        // Extract the first eSIM entry if present.
        $esim = $provider['obj']['esimList'][0] ?? null;

        // Build a minimal, safe payload for clients (usage/status only).
        $safeProvider = [
            'expires_at'      => $esim['expiredTime'] ?? null,
            'total_bytes'     => $esim['totalVolume'] ?? null,
            'used_bytes'      => $esim['orderUsage'] ?? null,
            'remaining_bytes' => (isset($esim['totalVolume'], $esim['orderUsage']) && is_numeric($esim['totalVolume']) && is_numeric($esim['orderUsage']))
                ? max(0, (int) $esim['totalVolume'] - (int) $esim['orderUsage'])
                : null,
            'esim_status'     => $esim['esimStatus'] ?? null,
            'smdp_status'     => $esim['smdpStatus'] ?? null,
            'esim_tran_no'     => $esim['esimTranNo'] ?? null,
            'location_codes'    => $esim['packageList'][0]['locationCode'] ?? null,
        ];

        return [
            'simcard'  => $simcard,
            'provider' => $safeProvider,
        ];
    }

    private function findExistingSimcardForOrderRequest(
        string $planIdHash,
        ?string $commerceOrderId,
        ?string $commerceOrderItemId,
        ?int $commerceUnit,
        ?string $idempotencyKey
    ): ?Simcard {
        if ($idempotencyKey !== null) {
            $existing = Simcard::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        if ($commerceOrderId !== null && $commerceOrderItemId !== null && $commerceUnit !== null) {
            $existing = Simcard::query()
                ->where('commerce_order_id', $commerceOrderId)
                ->where('commerce_order_item_id', $commerceOrderItemId)
                ->where('commerce_unit', $commerceUnit)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Backward-compatible idempotency. Commerce now persists/reuses the same
        // plan_id across queue retries, so this also prevents duplicates.
        return Simcard::query()
            ->where('plan_id_hash', $planIdHash)
            ->lockForUpdate()
            ->first();
    }

    private function attachCommerceIdempotencyMetadata(
        Simcard $simcard,
        ?string $commerceOrderId,
        ?string $commerceOrderItemId,
        ?int $commerceUnit,
        ?string $idempotencyKey
    ): void {
        $changed = false;

        if ($commerceOrderId !== null && empty($simcard->commerce_order_id)) {
            $simcard->commerce_order_id = $commerceOrderId;
            $changed = true;
        }

        if ($commerceOrderItemId !== null && empty($simcard->commerce_order_item_id)) {
            $simcard->commerce_order_item_id = $commerceOrderItemId;
            $changed = true;
        }

        if ($commerceUnit !== null && empty($simcard->commerce_unit)) {
            $simcard->commerce_unit = $commerceUnit;
            $changed = true;
        }

        if ($idempotencyKey !== null && empty($simcard->idempotency_key)) {
            $simcard->idempotency_key = $idempotencyKey;
            $changed = true;
        }

        if ($changed) {
            $simcard->save();
        }
    }

    private function normalizeNullableIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** Store/update nullable encrypted customer email for service/top-up notifications. */
    public function storeEmailOnSimcard(Simcard $simcard, ?string $email, ?string $emailSource = 'order'): void
    {
        $normalizedEmail = $this->crypto->normalizeEmail($email);

        if ($normalizedEmail === null) {
            return;
        }

        $simcard->email_enc = $this->crypto->encryptEmail($normalizedEmail);
        $simcard->email_hash = $this->crypto->deriveEmailHash($normalizedEmail);
        $simcard->email_opt_in_at = $simcard->email_opt_in_at ?? now();
        $simcard->email_source = $emailSource ?: 'order';
        $simcard->save();
    }

    /** Decrypt customer email for notification sending. Never expose this in normal API responses. */
    public function decryptSimcardEmail(Simcard $simcard): ?string
    {
        if (!$simcard->email_enc) {
            return null;
        }

        return $this->crypto->decryptEmail($simcard->email_enc);
    }

    /** Fetch install payload (AC) with a short retry loop */
    private function fetchInstallInfoWithRetry(string $planId): array
    {
        $planIdHash = $this->crypto->derivePlanHash($planId);

        $simcard = Simcard::where('plan_id_hash', $planIdHash)->firstOrFail();

        $externalOrderId = $this->crypto->decryptForPlan(
            $planId,
            $simcard->external_order_id_enc
        );

        for ($i = 0; $i < 10; $i++) {
            $provider = $this->provider->queryOrder($externalOrderId);

            $install = $this->buildInstallPayload($provider);

            // AC is the only thing we need for installation.
            if (!empty($install['ac'])) {
                if ($simcard->state !== 'OK') {
                    $simcard->state = 'OK';
                    $simcard->save();
                }

                return $install;
            }

            usleep(350_000); // 350ms
        }

        return [
            'ac' => null,
            'apn' => null,
        ];
    }

    /** Build install payload from provider response */
    private function buildInstallPayload(array $provider): array
    {
        $esim = $provider['obj']['esimList'][0] ?? null;

        if (!is_array($esim)) {
            return [
                'ac' => null,
                'apn' => null,
            ];
        }

        return [
            'ac' => $esim['ac'] ?? null,
            'apn' => $this->extractApn($esim),
        ];
    }

    private function extractApn(array $esim): ?string
    {
        $candidates = [
            $esim['apn'] ?? null,
            $esim['apnValue'] ?? null,
            $esim['accessPointName'] ?? null,
            $esim['installation']['apn'] ?? null,
            $esim['install']['apn'] ?? null,
            $esim['profile']['apn'] ?? null,
            $esim['packageList'][0]['apn'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
