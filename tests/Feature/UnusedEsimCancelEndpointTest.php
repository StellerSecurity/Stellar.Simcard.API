<?php

use App\Services\UnusedEsimCancellationService;
use Mockery\MockInterface;

beforeEach(function (): void {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
});

function unusedEsimCancelAuth(): array
{
    return ['Authorization' => 'Basic '.base64_encode('test-user:test-pass')];
}

it('cancels an unused esim through the internal sim route', function (): void {
    $this->mock(UnusedEsimCancellationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cancel')
            ->once()
            ->with('1234123412341234')
            ->andReturn([
                'status' => 'cancelled',
                'provider' => [
                    'esim_status' => 'GOT_RESOURCE',
                    'smdp_status' => 'RELEASED',
                    'used_bytes' => 0,
                    'cancelled_status' => 'CANCELED',
                ],
            ]);
    });

    $this->withHeaders(unusedEsimCancelAuth())
        ->postJson('/api/v1/sim/cancel', [
            'plan_id' => '1234 1234 1234 1234',
        ])
        ->assertOk()
        ->assertJsonPath('response_code', 200)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.provider.used_bytes', 0);
});

it('returns conflict when the provider reports the esim is no longer cancellable', function (): void {
    $this->mock(UnusedEsimCancellationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cancel')
            ->once()
            ->andThrow(new DomainException('The eSIM has already been installed or used and cannot be cancelled automatically.'));
    });

    $this->withHeaders(unusedEsimCancelAuth())
        ->postJson('/api/v1/sim/cancel', [
            'plan_id' => '1234123412341234',
        ])
        ->assertStatus(409)
        ->assertJsonPath('response_code', 409);
});

it('returns not found when the private plan id does not identify a simcard', function (): void {
    $this->mock(UnusedEsimCancellationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cancel')
            ->once()
            ->andReturn(null);
    });

    $this->withHeaders(unusedEsimCancelAuth())
        ->postJson('/api/v1/sim/cancel', [
            'plan_id' => '1234123412341234',
        ])
        ->assertNotFound()
        ->assertJsonPath('response_code', 404);
});

it('rejects malformed plan ids before the cancellation service runs', function (): void {
    $this->mock(UnusedEsimCancellationService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('cancel');
    });

    $this->withHeaders(unusedEsimCancelAuth())
        ->postJson('/api/v1/sim/cancel', [
            'plan_id' => 'invalid',
        ])
        ->assertStatus(400)
        ->assertJsonPath('response_code', 400);
});

it('returns a temporary provider error without claiming the esim was cancelled', function (): void {
    $this->mock(UnusedEsimCancellationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('cancel')
            ->once()
            ->andThrow(new RuntimeException('Provider confirmation is pending.'));
    });

    $this->withHeaders(unusedEsimCancelAuth())
        ->postJson('/api/v1/sim/cancel', [
            'plan_id' => '1234123412341234',
        ])
        ->assertStatus(502)
        ->assertJsonPath('response_code', 502);
});
