<?php

namespace App\Services;

use App\Models\Simcard;
use App\Services\Esim\EsimProvider;
use Illuminate\Support\Str;

class SimcardService
{
    public function __construct(
        private readonly EsimProvider $provider,
    ) {}

    public function listPlans(array $filters = []): array
    {
        return $this->provider->listPlans($filters);
    }

    public function orderEsim(int $userId, ?string $accountRef, string $packageCode): Simcard
    {
        $order = $this->provider->createOrder($packageCode);

        return Simcard::create([
            'id'               => (string) Str::uuid(),
            'plan_id'          => strtolower(Str::random(10)),
            'provider'         => 'esimaccess',
            'package_code'     => $packageCode,
            'external_order_id'=> $order->externalOrderId,
            'iccid'            => null,
            'state'            => 'pending',
            'user_id'          => $userId,
            'account_ref'      => $accountRef,
        ]);
    }

    public function queryStatusByPlanId(string $planId): array
    {
        $simcard = Simcard::where('plan_id', $planId)->firstOrFail();

        $providerResult = $this->provider->queryOrder($simcard->external_order_id);

        return [
            'simcard'  => $simcard,
            'provider' => $providerResult,
        ];
    }
}
