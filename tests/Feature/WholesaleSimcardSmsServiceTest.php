<?php

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use App\Services\SimcardService;
use App\Services\WholesaleSimcardSmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('requires the complete wholesale identity before sending to the stored ICCID', function (): void {
    $planId = '1234123412341234';
    $orderId = '11111111-1111-4111-8111-111111111111';
    $itemId = '22222222-2222-4222-8222-222222222222';
    $crypto = app(EsimCryptoService::class);

    Simcard::query()->create([
        'id' => (string) Str::uuid(),
        'plan_id_hash' => $crypto->derivePlanHash($planId),
        'provider' => 'esimaccess',
        'provider_account' => 'primary',
        'package_code' => 'EU-1GB',
        'external_order_id_enc' => $crypto->encryptForPlan($planId, 'provider-order-1'),
        'iccid_enc' => $crypto->encryptSensitiveValue('89852245280001113019'),
        'iccid_hash' => $crypto->deriveIccidHash('89852245280001113019'),
        'state' => 'OK',
        'commerce_order_id' => $orderId,
        'commerce_order_item_id' => $itemId,
        'commerce_unit' => 1,
    ]);

    $provider = $this->mock(EsimProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendSms')
            ->once()
            ->with('89852245280001113019', 'Service message', 'primary')
            ->andReturn(['success' => true]);
    });
    $simcards = $this->mock(SimcardService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('ensureProviderIccid');
    });
    $service = new WholesaleSimcardSmsService($provider, $crypto, $simcards);

    expect($service->send($planId, $orderId, $itemId, 1, 'Service message'))->toBeTrue();
    expect($service->send($planId, $orderId, '33333333-3333-4333-8333-333333333333', 1, 'No'))->toBeFalse();
});
