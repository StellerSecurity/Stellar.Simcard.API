<?php

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use App\Services\VirtualEsimQuotaService;
use Mockery\MockInterface;

uses(Tests\TestCase::class);

function quotaGib(int|float $gb): int
{
    return (int) round($gb * 1073741824);
}

it('clamps customer-visible quota usage without changing raw provider counters', function (): void {
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    /** @var EsimCryptoService&MockInterface $crypto */
    $crypto = Mockery::mock(EsimCryptoService::class);

    $service = new VirtualEsimQuotaService($provider, $crypto);
    $simcard = new Simcard();
    $simcard->virtual_fulfillment_recipe = [
        'strategy' => VirtualEsimQuotaService::STRATEGY,
        'target_data_bytes' => quotaGib(2),
        'quota' => [
            'entitlement_bytes' => quotaGib(2),
            'provider_allowance_bytes' => quotaGib(3),
            'state' => 'MONITORING',
        ],
    ];

    $usage = $service->effectiveUsage(
        $simcard,
        quotaGib(3),
        quotaGib(1.5),
        quotaGib(1.5),
    );

    expect($usage['total_bytes'])->toBe(quotaGib(2))
        ->and($usage['used_bytes'])->toBe(quotaGib(1.5))
        ->and($usage['remaining_bytes'])->toBe(quotaGib(0.5));
});

it('never exposes provider overrun beyond the advertised entitlement', function (): void {
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    /** @var EsimCryptoService&MockInterface $crypto */
    $crypto = Mockery::mock(EsimCryptoService::class);

    $service = new VirtualEsimQuotaService($provider, $crypto);
    $simcard = new Simcard();
    $simcard->virtual_fulfillment_recipe = [
        'strategy' => VirtualEsimQuotaService::STRATEGY,
        'target_data_bytes' => quotaGib(2),
        'quota' => [
            'entitlement_bytes' => quotaGib(2),
            'provider_allowance_bytes' => quotaGib(3),
            'state' => 'SUSPEND_QUEUED',
        ],
    ];

    $usage = $service->effectiveUsage($simcard, quotaGib(3), quotaGib(2.4), quotaGib(0.6));

    expect($usage['total_bytes'])->toBe(quotaGib(2))
        ->and($usage['used_bytes'])->toBe(quotaGib(2))
        ->and($usage['remaining_bytes'])->toBe(0);
});
