<?php

use App\Services\EsimAutoTopupService;
use App\Services\SimcardService;
use App\Services\VirtualEsimFulfillmentService;
use App\Services\VirtualEsimPlanResolver;
use Mockery\MockInterface;

it('rejects a fractional Daily Unlimited duration before provider ordering', function (): void {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';

    $this->mock(SimcardService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('orderAndGetInstallInfo');
    });
    $this->mock(EsimAutoTopupService::class);
    $this->mock(VirtualEsimFulfillmentService::class);
    $this->mock(VirtualEsimPlanResolver::class);

    $response = $this
        ->withHeader('Authorization', 'Basic '.base64_encode('test-user:test-pass'))
        ->postJson('/api/v1/sim/order', [
            'plan_id' => '1234567890123456',
            'packageCode' => 'DK_1_Daily',
            'days' => 8.5,
        ]);

    $response
        ->assertBadRequest()
        ->assertJsonPath('response_code', 400)
        ->assertJsonStructure(['errors' => ['days']]);
});
