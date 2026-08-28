<?php

use App\Models\SimcardAutoTopup;
use App\Models\SimcardAutoTopupAttempt;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use App\Services\EsimAutoTopupPaymentRecoveryService;
use App\Services\EsimAutoTopupService;
use App\Services\TopupService;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('arms exactly one new cycle after a replacement payment method is confirmed', function (): void {
    $configId = '00000000-0000-4000-8000-000000000010';
    $attemptId = '00000000-0000-4000-8000-000000000011';
    $sessionId = '00000000-0000-4000-8000-000000000012';

    SimcardAutoTopup::query()->create([
        'id' => $configId,
        'simcard_id' => '00000000-0000-4000-8000-000000000013',
        'parent_commerce_order_id' => '00000000-0000-4000-8000-000000000014',
        'parent_commerce_order_item_id' => '00000000-0000-4000-8000-000000000015',
        'commerce_unit' => 1,
        'enabled' => true,
        'state' => 'PAUSED',
        'trigger_percent' => 50,
        'preferred_data_bytes' => 1_000_000_000,
        'cycle' => 4,
        'failure_reason' => 'card_declined',
    ]);

    SimcardAutoTopupAttempt::query()->create([
        'id' => $attemptId,
        'auto_topup_id' => $configId,
        'cycle' => 4,
        'attempt_key' => 'auto-topup-attempt-000000000011',
        'status' => 'FAILED',
        'topup_session_id' => $sessionId,
        'failure_reason' => 'card_declined',
        'payment_failed_at' => now(),
        'payment_recovery_url_enc' => 'encrypted-url',
        'payment_recovery_expires_at' => now()->addMinutes(30),
    ]);

    $service = new EsimAutoTopupService(
        Mockery::mock(TopupService::class),
        Mockery::mock(EsimCryptoService::class),
        Mockery::mock(EsimProvider::class),
        Mockery::mock(VirtualEsimQuotaService::class),
        Mockery::mock(EsimAutoTopupPaymentRecoveryService::class),
    );

    expect($service->markPaymentMethodUpdated($sessionId))->toBe('armed')
        ->and($service->markPaymentMethodUpdated($sessionId))->toBe('already_processed');

    $config = SimcardAutoTopup::query()->findOrFail($configId);
    $attempt = SimcardAutoTopupAttempt::query()->findOrFail($attemptId);

    expect($config->state)->toBe('ARMED')
        ->and($config->cycle)->toBe(5)
        ->and($config->failure_reason)->toBeNull()
        ->and($attempt->payment_recovered_at)->not->toBeNull()
        ->and($attempt->payment_recovery_url_enc)->toBeNull()
        ->and($attempt->payment_recovery_expires_at)->toBeNull();
});
