<?php

use App\Services\Esim\EsimaccessProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

function unlimitedOrderProvider(): EsimaccessProvider
{
    return new EsimaccessProvider(
        baseUrl: 'https://provider.test',
        accounts: [
            'primary' => [
                'access_code' => 'test-access',
                'secret_key' => 'test-secret',
            ],
        ],
    );
}

it('keeps the existing fixed-plan provider order payload unchanged', function (): void {
    Http::fake([
        'https://provider.test/v1/open/esim/order' => Http::response([
            'success' => true,
            'obj' => ['orderNo' => 'B-FIXED-1'],
        ], 200),
    ]);

    unlimitedOrderProvider()->createOrder('DK_1_7');

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();
        $package = $payload['packageInfoList'][0] ?? [];

        return $request->url() === 'https://provider.test/v1/open/esim/order'
            && ($package['packageCode'] ?? null) === 'DK_1_7'
            && ($package['count'] ?? null) === 1
            && ! array_key_exists('periodNum', $package);
    });
});

it('adds periodNum only for a Daily Unlimited provider order', function (): void {
    Http::fake([
        'https://provider.test/v1/open/esim/order' => Http::response([
            'success' => true,
            'obj' => ['orderNo' => 'B-DAILY-8'],
        ], 200),
    ]);

    unlimitedOrderProvider()->createOrder('DK_1_Daily', 'primary', 8);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();
        $package = $payload['packageInfoList'][0] ?? [];

        return $request->url() === 'https://provider.test/v1/open/esim/order'
            && ($package['packageCode'] ?? null) === 'DK_1_Daily'
            && ($package['count'] ?? null) === 1
            && ($package['periodNum'] ?? null) === 8;
    });
});

it('rejects Daily Unlimited periods outside the provider range before sending a request', function (int $days): void {
    Http::fake();

    expect(fn () => unlimitedOrderProvider()->createOrder('DK_1_Daily', 'primary', $days))
        ->toThrow(RuntimeException::class, 'between 1 and 365 days');

    Http::assertNothingSent();
})->with([0, 366]);

it('keeps legacy plan-list request keys unchanged unless Daily filters are requested', function (): void {
    Http::fake([
        'https://provider.test/v1/open/package/list' => Http::response([
            'success' => true,
            'obj' => ['packageList' => []],
        ], 200),
    ]);

    $provider = unlimitedOrderProvider();
    $provider->listPlans(['locationCode' => 'DK', 'type' => 'BASE']);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->url() === 'https://provider.test/v1/open/package/list'
            && array_keys($payload) === ['locationCode', 'type', 'packageCode', 'iccid']
            && $payload['locationCode'] === 'DK'
            && $payload['type'] === 'BASE';
    });
});

it('supports additive slug and dataType filters for Daily Unlimited catalogue queries', function (): void {
    Http::fake([
        'https://provider.test/v1/open/package/list' => Http::response([
            'success' => true,
            'obj' => ['packageList' => []],
        ], 200),
    ]);

    $provider = unlimitedOrderProvider();
    $provider->listPlans([
        'locationCode' => 'DK',
        'type' => 'BASE',
        'slug' => 'DK_1_Daily',
        'dataType' => 2,
    ]);

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return ($payload['slug'] ?? null) === 'DK_1_Daily'
            && ($payload['dataType'] ?? null) === 2;
    });
});
