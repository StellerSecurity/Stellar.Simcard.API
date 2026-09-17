<?php

use App\Models\Simcard;
use App\Models\SimcardSupportReplacement;
use App\Services\Esim\EsimCryptoService;
use App\Services\SimcardService;
use App\Services\Support\EsimSupportReplacementService;
use App\Services\UnusedEsimCancellationService;
use App\Services\VirtualEsimFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns only safe status for the matching owned replacement request', function (): void {
    config([
        'esim.crypto.hash_key' => 'replacement-status-test-hash-key',
        'esim.crypto.master_key' => 'replacement-status-test-master-key',
    ]);
    $planId = '4538034324401446';
    $email = 'customer@example.test';
    $key = 'support-action:01a0acd4-83b6-71b9-8685-b990a9e5336b';
    $crypto = app(EsimCryptoService::class);
    $old = Simcard::query()->create([
        'plan_id_hash' => $crypto->derivePlanHash($planId),
        'email_hash' => $crypto->deriveEmailHash($email),
        'provider' => 'esimaccess',
        'provider_account' => 'primary',
        'package_code' => 'CA-75GB-20D',
        'state' => 'active',
    ]);
    SimcardSupportReplacement::query()->create([
        'old_simcard_id' => $old->id,
        'idempotency_key' => $key,
        'new_plan_id_enc' => $crypto->encryptSensitiveValue('1111222233334444'),
        'status' => 'failed',
        'last_error' => 'The provider rejected the revoke request.',
    ]);
    $simcards = Mockery::mock(SimcardService::class);
    $simcards->shouldReceive('findByPlanId')->once()->with($planId)->andReturn($old);
    $service = new EsimSupportReplacementService(
        $simcards,
        Mockery::mock(UnusedEsimCancellationService::class),
        Mockery::mock(VirtualEsimFulfillmentService::class),
        $crypto,
    );

    $status = $service->replacementStatus($planId, $email, $key);

    expect($status)->toMatchArray([
        'found' => true,
        'status' => 'failed',
        'old_retired' => false,
        'new_esim_created' => false,
        'last_error' => 'The provider rejected the revoke request.',
    ])->and($status)->not->toHaveKeys(['sim_id', 'new_plan_id', 'install']);
});
