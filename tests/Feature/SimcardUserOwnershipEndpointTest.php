<?php

use App\Exceptions\SimcardOwnershipConflictException;
use App\Models\Simcard;
use App\Services\SimcardService;
use Mockery\MockInterface;

beforeEach(function (): void {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
});

function simApiBasicAuth(): array
{
    return ['Authorization' => 'Basic '.base64_encode('test-user:test-pass')];
}

it('keeps an order anonymous when user_id is omitted', function (): void {
    $simcard = new Simcard();
    $simcard->state = 'pending';
    $simcard->provider = 'esimaccess';
    $simcard->package_code = 'EU-1GB';

    $this->mock(SimcardService::class, function (MockInterface $mock) use ($simcard): void {
        $mock->shouldReceive('orderAndGetInstallInfo')
            ->once()
            ->withArgs(fn (?int $userId): bool => $userId === null)
            ->andReturn([
                'simcard' => $simcard,
                'install' => ['ac' => null, 'apn' => null],
            ]);
    });

    $this->withHeaders(simApiBasicAuth())
        ->postJson('/api/v1/sim/order', [
            'plan_id' => '1234 1234 1234 1234',
            'packageCode' => 'EU-1GB',
        ])
        ->assertCreated()
        ->assertJsonPath('response_code', 201);
});

it('assigns a simcard through the project styled patch route', function (): void {
    $this->mock(SimcardService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('assignUserByPlanId')
            ->once()
            ->with('1234123412341234', 7345, 'mobile_app')
            ->andReturn([
                'status' => 'assigned',
                'simcard' => [
                    'id' => '00000000-0000-4000-8000-000000000001',
                    'package_code' => 'EU-1GB',
                ],
            ]);
    });

    $this->withHeaders(simApiBasicAuth())
        ->patchJson('/api/v1/sim/user', [
            'plan_id' => '1234 1234 1234 1234',
            'user_id' => 7345,
            'source' => 'mobile_app',
        ])
        ->assertOk()
        ->assertJsonPath('response_code', 200)
        ->assertJsonPath('data.status', 'assigned');
});

it('lists simcards through the project styled user route', function (): void {
    $this->mock(SimcardService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('listByUserId')
            ->once()
            ->with(7345)
            ->andReturn([
                [
                    'id' => '00000000-0000-4000-8000-000000000001',
                    'package_code' => 'EU-1GB',
                ],
            ]);
    });

    $this->withHeaders(simApiBasicAuth())
        ->postJson('/api/v1/sim/user', ['user_id' => 7345])
        ->assertOk()
        ->assertJsonPath('response_code', 200)
        ->assertJsonCount(1, 'data');
});

it('returns conflict instead of reassigning another users simcard', function (): void {
    $this->mock(SimcardService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('assignUserByPlanId')
            ->once()
            ->andThrow(new SimcardOwnershipConflictException(
                'The eSIM is already assigned to another user.'
            ));
    });

    $this->withHeaders(simApiBasicAuth())
        ->patchJson('/api/v1/sim/user', [
            'plan_id' => '1234123412341234',
            'user_id' => 7345,
        ])
        ->assertStatus(409)
        ->assertJsonPath('response_code', 409);
});

it('detaches one simcard only through a verified user request', function (): void {
    $this->mock(SimcardService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('detachUserByPlanId')
            ->once()
            ->with('1234123412341234', 7345)
            ->andReturn([
                'status' => 'detached',
                'simcard' => ['id' => '00000000-0000-4000-8000-000000000001'],
            ]);
    });

    $this->withHeaders(simApiBasicAuth())
        ->deleteJson('/api/v1/sim/user', [
            'plan_id' => '1234123412341234',
            'user_id' => 7345,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'detached');
});

it('detaches all user associations for account deletion', function (): void {
    $this->mock(SimcardService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('detachAllForUserId')
            ->once()
            ->with(7345)
            ->andReturn(3);
    });

    $this->withHeaders(simApiBasicAuth())
        ->deleteJson('/api/v1/sim/user/all', ['user_id' => 7345])
        ->assertOk()
        ->assertJsonPath('data.detached_count', 3);
});
