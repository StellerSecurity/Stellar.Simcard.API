<?php

namespace App\Services;

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
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
    public function orderEsim(int $userId, ?string $accountRef, string $packageCode, string $planId): Simcard
    {
        $planIdHash = $this->crypto->derivePlanHash($planId);
        $order      = $this->provider->createOrder($packageCode);

        $externalOrderIdEnc = $this->crypto->encryptForPlan($planId, $order->externalOrderId);

        return Simcard::create([
            'id'                    => (string) Str::uuid(),
            'plan_id_hash'          => $planIdHash,
            'provider'              => 'esimaccess',
            'package_code'          => $packageCode,
            'external_order_id_enc' => $externalOrderIdEnc,
            'iccid_enc'             => null,
            'state'                 => 'pending',
            'user_id'               => $userId,
            'account_ref'           => $accountRef,
        ]);
    }

    /** Query provider for usage/status for a given plan_id */
    public function queryStatusByPlanId(string $planId): array
    {
        $planIdHash = $this->crypto->derivePlanHash($planId);

        $simcard = Simcard::where('plan_id_hash', $planIdHash)->firstOrFail();

        $externalOrderId = $this->crypto->decryptForPlan(
            $planId,
            $simcard->external_order_id_enc
        );

        $providerResult = $this->provider->queryOrder($externalOrderId);

        return [
            'simcard'  => $simcard,
            'provider' => $providerResult,
        ];
    }
}
