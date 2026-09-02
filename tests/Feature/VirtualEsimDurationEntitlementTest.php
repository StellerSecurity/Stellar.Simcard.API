<?php

use App\Jobs\EnforceVirtualEsimDurationJob;
use App\Models\Simcard;
use App\Models\SimcardTopupSession;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

$_ENV['ESIM_PLAN_HASH_KEY'] = $_SERVER['ESIM_PLAN_HASH_KEY'] = 'duration-entitlement-test-hash-key';
$_ENV['ESIM_PLAN_MASTER_KEY'] = $_SERVER['ESIM_PLAN_MASTER_KEY'] = base64_encode(str_repeat('d', 32));

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function durationCappedSimcard(array $overrides = []): Simcard
{
    return Simcard::create(array_merge([
        'plan_id_hash' => hash('sha256', fake()->uuid()),
        'provider' => 'esimaccess',
        'provider_account' => 'primary',
        'package_code' => 'REAL-3GB-30D',
        'external_order_id_enc' => 'encrypted-order',
        'iccid_enc' => 'encrypted-iccid',
        'state' => 'active',
        'esim_status' => 'IN_USE',
        'activated_at' => now()->subDays(20),
        'virtual_fulfillment_recipe' => [
            'strategy' => 'exact_provider_composition_v1',
            'target_data_bytes' => 3 * 1073741824,
            'target_duration_days' => 20,
            'duration_entitlement' => [
                'enforced' => true,
                'target_duration_days' => 20,
                'entitled_duration_days' => 20,
                'state' => 'WAITING_FOR_ACTIVATION',
                'customer_expires_at' => null,
                'paid_topup_session_ids' => [],
            ],
        ],
    ], $overrides));
}

function durationService(?EsimProvider $provider = null, ?EsimCryptoService $crypto = null): VirtualEsimQuotaService
{
    /** @var EsimProvider&MockInterface $providerMock */
    $providerMock = $provider ?? Mockery::mock(EsimProvider::class);
    /** @var EsimCryptoService&MockInterface $cryptoMock */
    $cryptoMock = $crypto ?? Mockery::mock(EsimCryptoService::class);

    return new VirtualEsimQuotaService($providerMock, $cryptoMock);
}

it('anchors a 20 day entitlement at activation and queues suspension at the exact deadline', function (): void {
    Carbon::setTestNow('2026-09-02T12:00:00Z');
    Queue::fake();
    $simcard = durationCappedSimcard();

    $result = durationService()->processDurationStored($simcard);
    $simcard->refresh();

    expect($result['status'])->toBe('suspend_queued')
        ->and(data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.customer_expires_at'))
        ->toBe('2026-09-02T12:00:00+00:00')
        ->and(data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.state'))
        ->toBe('SUSPEND_QUEUED');

    Queue::assertPushed(EnforceVirtualEsimDurationJob::class, fn (EnforceVirtualEsimDurationJob $job): bool => $job->simcardId === (string) $simcard->id);
});

it('keeps validity waiting before first use and includes paid topup days when activation happens', function (): void {
    Carbon::setTestNow('2026-09-02T12:00:00Z');
    $simcard = durationCappedSimcard([
        'state' => 'ready',
        'esim_status' => 'GOT_RESOURCE',
        'activated_at' => null,
    ]);
    $session = new SimcardTopupSession([
        'id' => fake()->uuid(),
        'duration_days' => 7,
        'data_bytes' => 1073741824,
        'meta' => ['source' => 'customer_topup'],
    ]);
    $service = durationService();

    $service->extendDurationEntitlementForPaidTopup($simcard, $session);
    $simcard->refresh();

    expect(data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.entitled_duration_days'))->toBe(27)
        ->and(data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.customer_expires_at'))->toBeNull()
        ->and($service->effectiveRemainingValidityDays($simcard))->toBe(27);
});

it('suspends the provider after the customer deadline', function (): void {
    Carbon::setTestNow('2026-09-02T12:01:00Z');
    $simcard = durationCappedSimcard();
    $recipe = $simcard->virtual_fulfillment_recipe;
    data_set($recipe, 'duration_entitlement.customer_expires_at', '2026-09-02T12:00:00Z');
    data_set($recipe, 'duration_entitlement.state', 'SUSPEND_QUEUED');
    $simcard->virtual_fulfillment_recipe = $recipe;
    $simcard->save();

    /** @var EsimCryptoService&MockInterface $crypto */
    $crypto = Mockery::mock(EsimCryptoService::class);
    $crypto->shouldReceive('decryptSensitiveValue')->once()->with('encrypted-iccid')->andReturn('8945000000000000000');
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('suspendEsim')->once()->with('8945000000000000000', 'primary')->andReturn(['success' => true, 'errorCode' => '0']);

    $result = durationService($provider, $crypto)->enforceDurationSuspend((string) $simcard->id);
    $simcard->refresh();

    expect($result['status'])->toBe('suspended')
        ->and($simcard->state)->toBe('suspended')
        ->and($simcard->esim_status)->toBe('SUSPENDED')
        ->and(data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.state'))->toBe('SUSPENDED');
});

it('reactivates an expired virtual plan and starts paid topup validity from now', function (): void {
    Carbon::setTestNow('2026-09-02T12:01:00Z');
    $simcard = durationCappedSimcard([
        'state' => 'suspended',
        'esim_status' => 'SUSPENDED',
    ]);
    $recipe = $simcard->virtual_fulfillment_recipe;
    data_set($recipe, 'duration_entitlement.customer_expires_at', '2026-09-02T12:00:00Z');
    data_set($recipe, 'duration_entitlement.state', 'SUSPENDED');
    $simcard->virtual_fulfillment_recipe = $recipe;
    $simcard->save();

    /** @var EsimCryptoService&MockInterface $crypto */
    $crypto = Mockery::mock(EsimCryptoService::class);
    $crypto->shouldReceive('decryptSensitiveValue')->once()->with('encrypted-iccid')->andReturn('8945000000000000000');
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('unsuspendEsim')->once()->with('8945000000000000000', 'primary')->andReturn(['success' => true, 'errorCode' => '0']);
    $service = durationService($provider, $crypto);
    $session = new SimcardTopupSession([
        'id' => fake()->uuid(),
        'duration_days' => 7,
        'data_bytes' => 1073741824,
        'meta' => ['source' => 'customer_topup'],
    ]);

    $service->restoreForPaidTopup($simcard);
    $service->extendDurationEntitlementForPaidTopup($simcard->fresh(), $session);
    $simcard->refresh();

    expect($simcard->state)->toBe('active')
        ->and($simcard->esim_status)->toBe('IN_USE')
        ->and(data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.state'))->toBe('MONITORING')
        ->and(data_get($simcard->virtual_fulfillment_recipe, 'duration_entitlement.customer_expires_at'))
        ->toBe('2026-09-09T12:01:00+00:00')
        ->and($service->effectiveRemainingValidityDays($simcard))->toBe(7);
});
