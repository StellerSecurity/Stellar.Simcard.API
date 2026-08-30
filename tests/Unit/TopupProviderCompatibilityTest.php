<?php

use App\Models\Simcard;
use App\Services\TopupService;
use Illuminate\Support\Facades\Http;
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
})->with(['GOT_RESOURCE', 'IN_USE', 'USED_UP']);

it('allows auto topups while an eSIM is active or used up', function (string $providerStatus): void {
    $service = topupServiceWithoutConstructor();
    $simcard = new Simcard;
    $simcard->forceFill(['esim_status' => $providerStatus]);

    invokeTopupPrivate($service, 'assertAutoTopupEligible', [$simcard]);

    expect(true)->toBeTrue();
})->with(['IN_USE', 'USED_UP']);

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

it('does not offer vpn topup when the esim commerce link is incomplete', function (): void {
    Http::fake();

    $simcard = new Simcard;
    $simcard->forceFill([
        'commerce_order_id' => null,
        'commerce_order_item_id' => null,
        'commerce_unit' => null,
    ]);

    $offer = invokeTopupPrivate(topupServiceWithoutConstructor(), 'resolveVpnTopupOffer', [$simcard]);

    expect($offer['available'])->toBeFalse()
        ->and($offer['reason_code'])->toBe('ESIM_LINK_MISSING');
    Http::assertNothingSent();
});

it('publishes only a server verified vpn topup offer for a linked esim', function (): void {
    config()->set('services.stellar_commerce.vpn_topup_offer_url', 'https://commerce.test/api/v1/topupcheckoutcontroller/vpn-offer');

    Http::fake([
        'https://commerce.test/*' => Http::response([
            'response_code' => 200,
            'data' => [
                'available' => true,
                'name' => 'Add 30 days to Stellar VPN',
                'days' => 30,
                'price_cents' => 50,
                'currency' => 'EUR',
                'current_expires_at' => '2026-09-01T00:00:00Z',
                'projected_expires_at' => '2026-10-01T00:00:00Z',
                'consent_version' => 'esim_vpn_topup_v1',
                'internal_subscription_id' => 'must-not-leak',
            ],
        ]),
    ]);

    $simcard = new Simcard;
    $simcard->forceFill([
        'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'commerce_order_id' => '11111111-1111-4111-8111-111111111111',
        'commerce_order_item_id' => '22222222-2222-4222-8222-222222222222',
        'commerce_unit' => 2,
    ]);

    $offer = invokeTopupPrivate(topupServiceWithoutConstructor(), 'resolveVpnTopupOffer', [$simcard]);

    expect($offer['available'])->toBeTrue()
        ->and($offer['days'])->toBe(30)
        ->and($offer['price_cents'])->toBe(50)
        ->and($offer['consent_version'])->toBe('esim_vpn_topup_v1')
        ->and($offer)->not->toHaveKey('internal_subscription_id');

    Http::assertSent(fn ($request): bool => $request['parent_order_id'] === $simcard->commerce_order_id
        && $request['parent_order_item_id'] === $simcard->commerce_order_item_id
        && $request['commerce_unit'] === 2);
});

it('uses a separate checkout session when the vpn topup selection changes', function (): void {
    $service = topupServiceWithoutConstructor();
    $arguments = [
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        ['currency' => 'EUR', 'price_cents' => 538],
        'DK_10_30',
    ];

    $withoutVpn = invokeTopupPrivate($service, 'topupSessionIdempotencyKey', [...$arguments, false]);
    $withVpn = invokeTopupPrivate($service, 'topupSessionIdempotencyKey', [...$arguments, true]);
    $withVpnRetry = invokeTopupPrivate($service, 'topupSessionIdempotencyKey', [...$arguments, true]);

    expect($withVpn)->not->toBe($withoutVpn)
        ->and($withVpnRetry)->toBe($withVpn);
});
