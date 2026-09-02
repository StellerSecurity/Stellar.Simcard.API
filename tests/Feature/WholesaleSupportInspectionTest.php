<?php

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\SimcardService;
use App\Services\Support\EsimSupportReplacementService;
use App\Services\UnusedEsimCancellationService;
use App\Services\VirtualEsimFulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows SIM-ID technical inspection for wholesale eSIMs without exposing install secrets', function (): void {
    $simcard = new Simcard([
        'provider' => 'esimaccess',
        'package_code' => 'WHOLESALE-10GB',
        'state' => 'active',
        'commerce_order_id' => null,
    ]);
    $simcard->id = '00000000-0000-4000-8000-000000000001';
    $simcard->email_hash = 'different-email-hash';

    $simcards = Mockery::mock(SimcardService::class);
    $simcards->shouldReceive('findByPlanId')->once()->andReturn($simcard);
    $simcards->shouldReceive('queryStatusByPlanId')->once()->andReturn([
        'provider' => [
            'remaining_bytes' => 10737418240,
            'used_bytes' => 0,
            'esim_tran_no' => '26082912530032',
            'esim_status' => 'IN_USE',
            'smdp_status' => 'ENABLED',
        ],
        'install' => [
            'apn' => 'cmlink',
            'short_url' => 'https://p.qrsim.net/private-token',
            'lpa' => 'LPA:1$secret$token',
        ],
    ]);

    $crypto = Mockery::mock(EsimCryptoService::class);
    $crypto->shouldReceive('deriveEmailHash')->once()->with('wholesale@example.test')->andReturn('sender-email-hash');

    $service = new EsimSupportReplacementService(
        $simcards,
        Mockery::mock(UnusedEsimCancellationService::class),
        Mockery::mock(VirtualEsimFulfillmentService::class),
        $crypto,
    );

    $result = $service->inspect('1234123412341234', 'wholesale@example.test');

    expect($result)
        ->toMatchArray([
            'found' => true,
            'email_match' => false,
            'technical_support_verified' => true,
            'support_identity_mode' => 'sim_id_possession',
            'eligible_to_replace' => false,
        ])
        ->and(data_get($result, 'provider.esim_tran_no'))->toBeNull()
        ->and(data_get($result, 'install.apn'))->toBe('cmlink')
        ->and(data_get($result, 'install.short_url'))->toBeNull()
        ->and(data_get($result, 'install.lpa'))->toBeNull();
});

it('allows delegated technical support for a Commerce-linked SIM without exposing protected data', function (): void {
    $simcard = new Simcard([
        'provider' => 'esimaccess',
        'package_code' => 'RETAIL-10GB',
        'state' => 'active',
        'commerce_order_id' => 'retail-order-id',
    ]);
    $simcard->id = '00000000-0000-4000-8000-000000000002';
    $simcard->email_hash = 'different-email-hash';

    $simcards = Mockery::mock(SimcardService::class);
    $simcards->shouldReceive('findByPlanId')->once()->andReturn($simcard);
    $simcards->shouldReceive('queryStatusByPlanId')->once()->andReturn([
        'provider' => [
            'remaining_bytes' => 5368709120,
            'used_bytes' => 1024,
            'esim_tran_no' => '26082912530033',
            'esim_status' => 'IN_USE',
            'smdp_status' => 'ENABLED',
        ],
        'install' => [
            'apn' => 'internet',
            'short_url' => 'https://p.qrsim.net/protected-token',
            'lpa' => 'LPA:1$secret$token',
        ],
    ]);
    $crypto = Mockery::mock(EsimCryptoService::class);
    $crypto->shouldReceive('deriveEmailHash')->once()->andReturn('sender-email-hash');

    $service = new EsimSupportReplacementService(
        $simcards,
        Mockery::mock(UnusedEsimCancellationService::class),
        Mockery::mock(VirtualEsimFulfillmentService::class),
        $crypto,
    );

    $result = $service->inspect('1234123412341234', 'attacker@example.test');

    expect($result)
        ->toMatchArray([
            'found' => true,
            'email_match' => false,
            'technical_support_verified' => true,
            'support_identity_mode' => 'sim_id_possession',
            'eligible_to_replace' => false,
        ])
        ->and(data_get($result, 'provider.esim_tran_no'))->toBeNull()
        ->and(data_get($result, 'install.apn'))->toBe('internet')
        ->and(data_get($result, 'install.short_url'))->toBeNull()
        ->and(data_get($result, 'install.lpa'))->toBeNull();
});

it('exposes full diagnostics only to the provider-case executor without replacement authority', function (): void {
    $simcard = new Simcard([
        'provider' => 'esimaccess',
        'package_code' => 'RETAIL-10GB',
        'state' => 'active',
        'commerce_order_id' => 'retail-order-id',
    ]);
    $simcard->id = '00000000-0000-4000-8000-000000000003';

    $simcards = Mockery::mock(SimcardService::class);
    $simcards->shouldReceive('findByPlanId')->once()->andReturn($simcard);
    $simcards->shouldReceive('queryStatusByPlanId')->once()->andReturn([
        'provider' => [
            'remaining_bytes' => 5368709120,
            'used_bytes' => 1024,
            'esim_tran_no' => '26082912530034',
            'esim_status' => 'IN_USE',
            'smdp_status' => 'ENABLED',
        ],
        'install' => [
            'apn' => 'internet',
            'short_url' => 'https://p.qrsim.net/protected-token',
            'lpa' => 'LPA:1$secret$token',
        ],
    ]);

    $service = new EsimSupportReplacementService(
        $simcards,
        Mockery::mock(UnusedEsimCancellationService::class),
        Mockery::mock(VirtualEsimFulfillmentService::class),
        Mockery::mock(EsimCryptoService::class),
    );

    $result = $service->inspectForProviderCase('1234123412341234');

    expect($result)
        ->toMatchArray([
            'technical_support_verified' => true,
            'support_identity_mode' => 'provider_case_executor',
            'eligible_to_replace' => false,
        ])
        ->and(data_get($result, 'provider.esim_tran_no'))->toBe('26082912530034')
        ->and(data_get($result, 'install.apn'))->toBe('internet')
        ->and(data_get($result, 'install.short_url'))->toBeNull()
        ->and(data_get($result, 'install.lpa'))->toBeNull();
});
