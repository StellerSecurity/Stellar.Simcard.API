<?php

use App\Services\Esim\EsimProvider;
use App\Services\VirtualEsimPlanResolver;
use Mockery\MockInterface;

uses(Tests\TestCase::class);

function gib(int|float $gb): int
{
    return (int) round($gb * 1073741824);
}

it('resolves a 6GB 30-day virtual plan as an exact 3GB 15-day base plus matching topup', function (): void {
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('listPlans')
        ->once()
        ->with([
            'type' => 'TOPUP',
            'packageCode' => 'BASE_3_15',
        ], 'primary')
        ->andReturn([
            'success' => true,
            'obj' => [
                'packageList' => [[
                    'packageCode' => 'TOPUP_3_15',
                    'slug' => 'MK_3_15',
                    'name' => 'North Macedonia 3GB 15Days',
                    'price' => 31000,
                    'currencyCode' => 'USD',
                    'volume' => gib(3),
                    'duration' => 15,
                    'durationUnit' => 'DAY',
                    'dataType' => 1,
                ]],
            ],
        ]);

    $resolver = new VirtualEsimPlanResolver($provider);
    $recipe = $resolver->resolve([
        [
            'package_code' => 'BASE_5_30',
            'data_bytes' => gib(5),
            'duration_days' => 30,
        ],
        [
            'package_code' => 'BASE_3_15',
            'data_bytes' => gib(3),
            'duration_days' => 15,
        ],
    ], gib(6), 30);

    expect($recipe['strategy'])->toBe('base_plus_included_topups_v1')
        ->and($recipe['base']['package_code'])->toBe('BASE_3_15')
        ->and($recipe['topups'])->toHaveCount(1)
        ->and($recipe['topups'][0]['provider_topup_value'])->toBe('TOPUP_3_15')
        ->and($recipe['delivered_data_bytes'])->toBe(gib(6))
        ->and($recipe['delivered_duration_days'])->toBe(30);
});

it('supports mixed topup packages and remains generic across virtual sizes', function (): void {
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('listPlans')
        ->once()
        ->with([
            'type' => 'TOPUP',
            'packageCode' => 'BASE_3_15',
        ], 'primary')
        ->andReturn([
            'success' => true,
            'obj' => [
                'packageList' => [
                    [
                        'packageCode' => 'TOPUP_3_10',
                        'slug' => 'X_3_10',
                        'price' => 25000,
                        'currencyCode' => 'USD',
                        'volume' => gib(3),
                        'duration' => 10,
                        'durationUnit' => 'DAY',
                        'dataType' => 1,
                    ],
                    [
                        'packageCode' => 'TOPUP_2_5',
                        'slug' => 'X_2_5',
                        'price' => 18000,
                        'currencyCode' => 'USD',
                        'volume' => gib(2),
                        'duration' => 5,
                        'durationUnit' => 'DAY',
                        'dataType' => 1,
                    ],
                ],
            ],
        ]);

    $resolver = new VirtualEsimPlanResolver($provider);
    $recipe = $resolver->resolve([[
        'package_code' => 'BASE_3_15',
        'data_bytes' => gib(3),
        'duration_days' => 15,
    ]], gib(8), 30);

    expect($recipe['topups'])->toHaveCount(2)
        ->and($recipe['delivered_data_bytes'])->toBe(gib(8))
        ->and($recipe['delivered_duration_days'])->toBe(30);
});


it('resolves Denmark 12GB 30-day using exact data even when included topups extend validity', function (): void {
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('listPlans')
        ->once()
        ->with([
            'type' => 'TOPUP',
            'packageCode' => 'DK_10_30',
        ], 'primary')
        ->andReturn([
            'success' => true,
            'obj' => [
                'packageList' => [[
                    'packageCode' => 'TOPUP_DK_1_7',
                    'slug' => 'DK_1_7_TOPUP',
                    'name' => 'Denmark 1GB 7Days',
                    'price' => 5700,
                    'currencyCode' => 'USD',
                    'volume' => gib(1),
                    'duration' => 7,
                    'durationUnit' => 'DAY',
                    'dataType' => 1,
                ]],
            ],
        ]);

    $resolver = new VirtualEsimPlanResolver($provider);
    $recipe = $resolver->resolve([[
        'package_code' => 'DK_10_30',
        'data_bytes' => gib(10),
        'duration_days' => 30,
    ]], gib(12), 30);

    expect($recipe['base']['package_code'])->toBe('DK_10_30')
        ->and($recipe['topups'])->toHaveCount(2)
        ->and($recipe['delivered_data_bytes'])->toBe(gib(12))
        ->and($recipe['delivered_duration_days'])->toBe(44)
        ->and($recipe['validity_overdelivery_days'])->toBe(14);
});

it('fails closed when no exact-data composition exists and never accepts a larger-data base', function (): void {
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldNotReceive('listPlans');

    $resolver = new VirtualEsimPlanResolver($provider);

    expect(fn () => $resolver->resolve([[
        'package_code' => 'OLD_NEXT_LARGER_10GB',
        'data_bytes' => gib(10),
        'duration_days' => 30,
    ]], gib(6), 30))->toThrow(
        RuntimeException::class,
        'Virtual plan has no eligible real provider base packages.',
    );
});
