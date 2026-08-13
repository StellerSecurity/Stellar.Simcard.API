<?php

use App\Services\TopupService;

uses(Tests\TestCase::class);

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
