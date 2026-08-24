<?php

use App\Models\Simcard;
use App\Services\VirtualEsimFulfillmentService;
use Mockery\MockInterface;

it('routes virtual orders through the isolated exact-composition service', function () {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';

    $simcard = new Simcard();
    $simcard->forceFill([
        'id' => '00000000-0000-4000-8000-000000000010',
        'state' => 'OK',
        'provider' => 'esimaccess',
        'package_code' => 'BASE_3_15',
        'user_ref' => null,
    ]);

    $this->mock(VirtualEsimFulfillmentService::class, function (MockInterface $mock) use ($simcard): void {
        $mock->shouldReceive('orderAndCompose')
            ->once()
            ->andReturn([
                'simcard' => $simcard,
                'install' => ['ac' => 'LPA:1$example'],
                'virtual_fulfillment' => [
                    'strategy' => 'base_plus_included_topups_v1',
                    'base' => ['package_code' => 'BASE_3_15'],
                    'topups' => [['package_code' => 'MK_3_15']],
                    'delivered_data_bytes' => 6442450944,
                    'delivered_duration_days' => 30,
                    'status' => 'TOPUPS_QUEUED',
                ],
            ]);
    });

    $response = $this
        ->withHeader('Authorization', 'Basic '.base64_encode('test-user:test-pass'))
        ->postJson('/api/v1/sim/virtual-order', [
            'plan_id' => '1234567890123456',
            'commerce_order_id' => '00000000-0000-4000-8000-000000000001',
            'commerce_order_item_id' => '00000000-0000-4000-8000-000000000002',
            'commerce_unit' => 1,
            'idempotency_key' => str_repeat('a', 64),
            'virtual_plan' => [
                'target_data_bytes' => 6442450944,
                'target_duration_days' => 30,
                'candidates' => [[
                    'package_code' => 'BASE_3_15',
                    'data_bytes' => 3221225472,
                    'duration_days' => 15,
                ]],
            ],
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('response_code', 201)
        ->assertJsonPath('data.simcard.package_code', 'BASE_3_15')
        ->assertJsonPath('data.virtual_fulfillment.delivered_duration_days', 30)
        ->assertJsonPath('data.virtual_fulfillment.status', 'TOPUPS_QUEUED');
});

it('preflights virtual plans without creating an esim', function () {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';

    $this->mock(\App\Services\VirtualEsimPlanResolver::class, function (MockInterface $mock): void {
        $mock->shouldReceive('resolve')
            ->once()
            ->andReturn([
                'strategy' => 'base_plus_included_topups_v1',
                'base' => ['package_code' => 'BASE_3_15'],
                'topups' => [['package_code' => 'MK_3_15']],
                'included_topup_count' => 1,
                'delivered_data_bytes' => 6442450944,
                'delivered_duration_days' => 30,
            ]);
    });

    $response = $this
        ->withHeader('Authorization', 'Basic '.base64_encode('test-user:test-pass'))
        ->postJson('/api/v1/sim/virtual-resolve', [
            'virtual_plan' => [
                'target_data_bytes' => 6442450944,
                'target_duration_days' => 30,
                'candidates' => [[
                    'package_code' => 'BASE_3_15',
                    'data_bytes' => 3221225472,
                    'duration_days' => 15,
                ]],
            ],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('response_code', 200)
        ->assertJsonPath('data.virtual_fulfillment.base.package_code', 'BASE_3_15')
        ->assertJsonPath('data.virtual_fulfillment.included_topup_count', 1)
        ->assertJsonPath('data.virtual_fulfillment.delivered_duration_days', 30);
});
