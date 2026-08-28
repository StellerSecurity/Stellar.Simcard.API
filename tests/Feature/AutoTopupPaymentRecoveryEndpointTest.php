<?php

use App\Services\EsimAutoTopupService;
use Mockery\MockInterface;

beforeEach(function (): void {
    putenv('APPSETTING_API_USERNAME_STELLAR_SIM_API=test-user');
    putenv('APPSETTING_API_PASSWORD_STELLAR_SIM_API=test-pass');
    $_ENV['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_ENV['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
    $_SERVER['APPSETTING_API_USERNAME_STELLAR_SIM_API'] = 'test-user';
    $_SERVER['APPSETTING_API_PASSWORD_STELLAR_SIM_API'] = 'test-pass';
});

it('records a failed payment and starts recovery delivery', function (): void {
    $sessionId = '00000000-0000-4000-8000-000000000001';

    $this->mock(EsimAutoTopupService::class, function (MockInterface $mock) use ($sessionId): void {
        $mock->shouldReceive('markPaymentFailed')
            ->once()
            ->with($sessionId, 'card_declined');
    });

    $this->withHeader('Authorization', 'Basic '.base64_encode('test-user:test-pass'))
        ->postJson('/api/v1/autotopupcontroller/payment-failed', [
            'topup_session_id' => $sessionId,
            'reason' => 'card_declined',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'recorded');
});

it('arms a new cycle after Commerce confirms the replacement card', function (): void {
    $sessionId = '00000000-0000-4000-8000-000000000002';

    $this->mock(EsimAutoTopupService::class, function (MockInterface $mock) use ($sessionId): void {
        $mock->shouldReceive('markPaymentMethodUpdated')
            ->once()
            ->with($sessionId)
            ->andReturn('armed');
    });

    $this->withHeader('Authorization', 'Basic '.base64_encode('test-user:test-pass'))
        ->postJson('/api/v1/autotopupcontroller/payment-method-updated', [
            'topup_session_id' => $sessionId,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'armed');
});

it('rejects an invalid recovery callback session id', function (): void {
    $this->withHeader('Authorization', 'Basic '.base64_encode('test-user:test-pass'))
        ->postJson('/api/v1/autotopupcontroller/payment-method-updated', [
            'topup_session_id' => 'not-a-uuid',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('response_code', 422);
});
