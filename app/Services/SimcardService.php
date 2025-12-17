<?php

namespace App\Services;

use App\Models\Simcard;
use App\Services\Esim\EsimProvider;
use App\Services\Esim\EsimCryptoService;
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

    /** Create eSIM order using a client-side generated plan_id */
    public function orderEsim(
        int $userId,
        ?string $accountRef,
        string $packageCode,
        string $planId
    ): Simcard {
        $planIdHash = $this->crypto->derivePlanHash($planId);
        $order      = $this->provider->createOrder($packageCode);

        $externalOrderIdEnc = $this->crypto->encryptForPlan(
            $planId,
            $order->externalOrderId
        );

        return Simcard::create([
            'id'                    => (string) Str::uuid(),
            'plan_id_hash'          => $planIdHash,
            'provider'              => 'esimaccess',
            'package_code'          => $packageCode,
            'external_order_id_enc' => $externalOrderIdEnc,
            'iccid_enc'             => null,
            'state'                 => 'OK',
            'user_id'               => $userId,
            'account_ref'           => $accountRef,
        ]);
    }

    /** Query provider for usage/status for a given plan_id */
    /** Query provider for usage/status for a given plan_id */
    public function queryStatusByPlanId(string $planId): array
    {
        $planIdHash = $this->crypto->derivePlanHash($planId);

        $simcard = Simcard::where('plan_id_hash', $planIdHash)->firstOrFail();

        $externalOrderId = $this->crypto->decryptForPlan(
            $planId,
            $simcard->external_order_id_enc
        );

        $provider = $this->provider->queryOrder($externalOrderId);

        // Extract the first eSIM entry if present.
        $esim = $provider['obj']['esimList'][0] ?? null;

        // Build a minimal, safe payload for clients.
        $safeProvider = [
            'expires_at'      => $esim['expiredTime'] ?? null,
            'total_bytes'     => $esim['totalVolume'] ?? null,
            'used_bytes'      => $esim['orderUsage'] ?? null,
            'remaining_bytes' => (isset($esim['totalVolume'], $esim['orderUsage']) && is_numeric($esim['totalVolume']) && is_numeric($esim['orderUsage']))
                ? max(0, (int) $esim['totalVolume'] - (int) $esim['orderUsage'])
                : null,

            // Only include what the app actually needs.
            'qr_code_url'     => $esim['qrCodeUrl'] ?? null,
            'short_url'       => $esim['shortUrl'] ?? null,

            'esim_status'     => $esim['esimStatus'] ?? null,
            'smdp_status'     => $esim['smdpStatus'] ?? null,
        ];

        return [
            'simcard'  => $simcard,
            'provider' => $safeProvider,
        ];
    }


}
