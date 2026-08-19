<?php

use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use App\Services\UnusedEsimCancellationService;
use Mockery\MockInterface;

function cancellationEligibility(array $providerEsim): string
{
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    /** @var EsimCryptoService&MockInterface $crypto */
    $crypto = Mockery::mock(EsimCryptoService::class);

    $service = new UnusedEsimCancellationService($provider, $crypto);
    $method = new \ReflectionMethod($service, 'providerEligibility');
    $method->setAccessible(true);

    return $method->invoke($service, $providerEsim);
}

it('allows the documented fresh provider state when order usage is omitted', function (): void {
    expect(cancellationEligibility([
        'smdpStatus' => 'RELEASED',
        'esimStatus' => 'GOT_RESOURCE',
        'activateTime' => null,
        'eid' => '',
    ]))->toBe('cancellable');
});

it('treats provider allocation states as retryable instead of installed or used', function (): void {
    expect(cancellationEligibility([
        'smdpStatus' => 'RELEASED',
        'esimStatus' => 'GETTING_RESOURCE',
        'orderUsage' => 0,
        'activateTime' => null,
        'eid' => '',
    ]))->toBe('transitional');
});

it('blocks cancellation when provider usage is positive', function (): void {
    expect(cancellationEligibility([
        'smdpStatus' => 'RELEASED',
        'esimStatus' => 'GOT_RESOURCE',
        'orderUsage' => 1,
        'activateTime' => null,
        'eid' => '',
    ]))->toBe('blocked');
});

it('blocks cancellation when the profile has installation evidence', function (): void {
    expect(cancellationEligibility([
        'smdpStatus' => 'ENABLED',
        'esimStatus' => 'GOT_RESOURCE',
        'orderUsage' => 0,
        'activateTime' => null,
        'eid' => '89049032000000000000000000000001',
    ]))->toBe('blocked');
});
