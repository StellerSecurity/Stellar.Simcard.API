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
        ?string $emailSource = 'order'
    ): Simcard {

        $planId = preg_replace('/\s+/', '', (string) $planId);
        $planIdHash = $this->crypto->derivePlanHash($planId);

        return DB::transaction(function () use ($planIdHash, $userId, $accountRef, $packageCode, $planId, $email, $emailSource) {
            // If a record already exists for this plan_id, do not create a new provider order.
            $existing = Simcard::where('plan_id_hash', $planIdHash)->first();
            if ($existing) {
                $this->storeEmailOnSimcard($existing, $email, $emailSource);

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
                'state'                 => 'pending',
                'user_id'               => $userId,
                'purchased_on'          => now()->toDateString(),
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
        ?string $emailSource = 'order'
    ): array {
        $simcard = $this->orderEsim(
            userId: $userId,
            accountRef: $accountRef,
            packageCode: $packageCode,
            planId: $planId,
            email: $email,
            emailSource: $emailSource,
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
