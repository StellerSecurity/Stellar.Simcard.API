<?php

use App\Services\TopupService;
use Mockery\MockInterface;

it('prepares an internally paid top-up session without creating a commerce checkout', function () {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';

    $this->mock(TopupService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('preparePaidSession')
            ->once()
            ->with(
                'TOKEN-123',
                'EU-30_1_30',
                '1234567890abcdef1234567890abcdef',
                '00000000-0000-4000-8000-000000000001',
                '00000000-0000-4000-8000-000000000002',
                'wholesale_wallet_topup',
            )
            ->andReturn([
                'status' => 'PAID',
                'topup_session_id' => '00000000-0000-4000-8000-000000000003',
                'package_code' => 'EU-30_1_30',
                'supplier_reference' => null,
                'idempotent' => false,
            ]);
    });

    $response = $this
        ->withHeader('Authorization', 'Basic '.base64_encode('test-user:test-pass'))
        ->postJson('/api/v1/topupcontroller/prepare', [
            'token' => 'TOKEN-123',
            'package_code' => 'EU-30_1_30',
            'idempotency_key' => '1234567890abcdef1234567890abcdef',
            'external_reference' => '00000000-0000-4000-8000-000000000001',
            'payment_reference' => '00000000-0000-4000-8000-000000000002',
            'source' => 'wholesale_wallet_topup',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('response_code', 200)
        ->assertJsonPath('data.status', 'PAID')
        ->assertJsonPath('data.topup_session_id', '00000000-0000-4000-8000-000000000003');
});
