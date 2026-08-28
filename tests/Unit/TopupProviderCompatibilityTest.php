<?php

use App\Models\Simcard;
use App\Services\TopupService;
use Tests\TestCase;

uses(TestCase::class);

function topupServiceWithoutConstructor(): TopupService
{
    $reflection = new ReflectionClass(TopupService::class);

    /** @var TopupService $service */
    $service = $reflection->newInstanceWithoutConstructor();

    return $service;
}

function invokeTopupPrivate(TopupService $service, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod(TopupService::class, $method);

    return $reflection->invokeArgs($service, $arguments);
}

it('keeps the public slug while retaining the provider topup package code', function (): void {
    $service = topupServiceWithoutConstructor();

    $plans = invokeTopupPrivate($service, 'normalizeTopupPlans', [[
        'success' => true,
        'obj' => [
            'packageList' => [[
                'packageCode' => 'TOPUP_JC066',
                'slug' => 'JP_10_30',
                'name' => 'Japan 10GB 30Days',
                'price' => 47000,
                'currencyCode' => 'USD',
                'volume' => 10737418240,
                'duration' => 30,
                'durationUnit' => 'DAY',
                'location' => 'JP',
                'locationCode' => 'JP',
                'supportTopUpType' => 1,
                'dataType' => 1,
            ]],
        ],
    ]]);

    expect($plans)->toHaveCount(1)
        ->and($plans[0]['package_code'])->toBe('JP_10_30')
        ->and($plans[0]['provider_topup_value'])->toBe('TOPUP_JC066')
        ->and($plans[0]['provider_topup_code'])->toBe('TOPUP_JC066')
        ->and($plans[0]['provider_topup_slug'])->toBe('JP_10_30')
        ->and($plans[0]['topup_payload_type'])->toBe('package_code');
});

it('publishes customer topup pricing in EUR while retaining the provider currency metadata', function (): void {
    $service = topupServiceWithoutConstructor();

    $customerPlan = invokeTopupPrivate($service, 'customerTopupPlan', [[
        'package_code' => 'JP_10_30',
        'price_cents' => 3525,
        'unit_price_cents' => 3525,
        'currency' => 'USD',
        'provider_price_cents' => 3525,
        'provider_currency' => 'USD',
        'pricing_source' => 'provider_raw',
    ]]);

    expect($customerPlan['price_cents'])->toBe(3525)
        ->and($customerPlan['unit_price_cents'])->toBe(3525)
        ->and($customerPlan['currency'])->toBe('EUR')
        ->and($customerPlan['customer_currency'])->toBe('EUR')
        ->and($customerPlan['provider_currency'])->toBe('USD')
        ->and($customerPlan['original_currency'])->toBe('USD')
        ->and($customerPlan['pricing_source'])->toBe('simcard_api_eur')
        ->and($customerPlan['pricing_version'])->toBe('topup_eur_v1');
});

it('keeps an ICCID-authorized fixed TOPUP row even when that recharge row reports supportTopUpType 1', function (): void {
    $service = topupServiceWithoutConstructor();

    $plans = [[
        'package_code' => 'TH_3_15',
        'provider_topup_value' => 'TOPUP_JC046',
        'provider_topup_code' => 'TOPUP_JC046',
        'provider_topup_slug' => 'TH_3_15',
        'support_topup_type' => 1,
        'data_type' => 1,
        'location_code' => 'TH',
    ]];

    $fixedPlans = invokeTopupPrivate($service, 'fixedTopupPlans', [$plans]);

    expect($fixedPlans)->toHaveCount(1)
        ->and($fixedPlans[0]['package_code'])->toBe('TH_3_15')
        ->and($fixedPlans[0]['provider_topup_value'])->toBe('TOPUP_JC046');
});

it('accepts both the legacy public slug and the provider topup code when matching a plan', function (): void {
    $service = topupServiceWithoutConstructor();
    $plans = [[
        'package_code' => 'JP_10_30',
        'provider_topup_value' => 'TOPUP_JC066',
        'provider_topup_code' => 'TOPUP_JC066',
        'provider_topup_slug' => 'JP_10_30',
    ]];

    $bySlug = invokeTopupPrivate($service, 'findPlanByPackageCode', [$plans, 'JP_10_30']);
    $byProviderCode = invokeTopupPrivate($service, 'findPlanByPackageCode', [$plans, 'TOPUP_JC066']);

    expect($bySlug)->not->toBeNull()
        ->and($byProviderCode)->not->toBeNull();
});

it('treats supplier balance exhaustion as retryable', function (): void {
    $service = topupServiceWithoutConstructor();

    $retryable = invokeTopupPrivate($service, 'providerTopupFailureIsRetryable', [[
        'success' => false,
        'errorCode' => '200007',
        'errorMsg' => 'the balance is insufficient',
    ]]);

    $permanent = invokeTopupPrivate($service, 'providerTopupFailureIsRetryable', [[
        'success' => false,
        'errorCode' => '310242',
        'errorMsg' => 'top up data plan code does not exist',
    ]]);

    expect($retryable)->toBeTrue()
        ->and($permanent)->toBeFalse();
});

it('does not expose Day Pass plans through the fixed-plan fulfillment contract', function (): void {
    $service = topupServiceWithoutConstructor();

    $plans = [[
        'package_code' => 'KZ_1_Daily',
        'provider_topup_value' => 'KZ_1_Daily',
        'provider_topup_slug' => 'KZ_1_Daily',
        'support_topup_type' => 3,
        'data_type' => 2,
        'location_code' => 'KZ',
    ]];

    $fixedPlans = invokeTopupPrivate($service, 'fixedTopupPlans', [$plans]);

    expect($fixedPlans)->toBe([]);
});

it('allows customer and internally funded virtual topups before first use', function (): void {
    $service = topupServiceWithoutConstructor();
    $simcard = new Simcard;
    $simcard->forceFill([
        'esim_status' => 'GOT_RESOURCE',
        'state' => 'pending',
    ]);

    // Included virtual composition may run before installation.
    invokeTopupPrivate($service, 'assertIncludedVirtualTopupEligible', [$simcard]);

    // eSIMAccess also permits customer top-up while the profile is New.
    invokeTopupPrivate($service, 'assertTopupEligible', [$simcard]);

    expect(true)->toBeTrue();
});

it('allows customer topups for all eSIMAccess topup lifecycle states', function (string $providerStatus): void {
    $service = topupServiceWithoutConstructor();
    $simcard = new Simcard;
    $simcard->forceFill([
        'esim_status' => $providerStatus,
        'state' => 'pending',
    ]);

    invokeTopupPrivate($service, 'assertTopupEligible', [$simcard]);

    expect(true)->toBeTrue();
})->with(['GOT_RESOURCE', 'IN_USE']);

it('allows legacy records in ready or active local states when provider status is unavailable', function (string $state): void {
    $service = topupServiceWithoutConstructor();
    $simcard = new Simcard;
    $simcard->forceFill([
        'esim_status' => null,
        'state' => $state,
    ]);

    invokeTopupPrivate($service, 'assertTopupEligible', [$simcard]);

    expect(true)->toBeTrue();
})->with(['OK', 'active']);

it('continues to reject terminal eSIMAccess states for customer topups', function (string $providerStatus): void {
    $service = app(TopupService::class);
    $simcard = new Simcard;
    $simcard->forceFill([
        'esim_status' => $providerStatus,
        'state' => 'active',
    ]);

    expect(fn () => invokeTopupPrivate($service, 'assertTopupEligible', [$simcard]))
        ->toThrow(RuntimeException::class, 'Only New or active, unexpired eSIMs can be topped up.');
})->with(['EXPIRED', 'CANCELLED', 'REVOKED']);

it('continues to reject unallocated legacy records', function (): void {
    $service = app(TopupService::class);
    $simcard = new Simcard;
    $simcard->forceFill([
        'esim_status' => null,
        'state' => 'pending',
    ]);

    expect(fn () => invokeTopupPrivate($service, 'assertTopupEligible', [$simcard]))
        ->toThrow(RuntimeException::class, 'Only New or active, unexpired eSIMs can be topped up.');
});

it('blocks included virtual topups for terminal esim states', function (): void {
    $service = topupServiceWithoutConstructor();
    $simcard = new Simcard;
    $simcard->forceFill(['esim_status' => 'EXPIRED']);

    expect(fn () => invokeTopupPrivate($service, 'assertIncludedVirtualTopupEligible', [$simcard]))
        ->toThrow(RuntimeException::class, 'no longer eligible');
});
