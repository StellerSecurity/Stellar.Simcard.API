<?php

use App\Services\EsimAutoTopupService;

function autoTopupServiceWithoutConstructor(): EsimAutoTopupService
{
    $reflection = new ReflectionClass(EsimAutoTopupService::class);

    /** @var EsimAutoTopupService $service */
    $service = $reflection->newInstanceWithoutConstructor();

    return $service;
}

function autoTopupLifecycleIsEligible(EsimAutoTopupService $service, mixed $status): bool
{
    $method = new ReflectionMethod(EsimAutoTopupService::class, 'isAutoTopupLifecycleEligible');

    return $method->invoke($service, $status);
}

it('accepts active and fully consumed eSIM lifecycle states', function (string $status): void {
    expect(autoTopupLifecycleIsEligible(autoTopupServiceWithoutConstructor(), $status))->toBeTrue();
})->with(['IN_USE', 'USED_UP', 'used_up']);

it('rejects non-top-up lifecycle states', function (mixed $status): void {
    expect(autoTopupLifecycleIsEligible(autoTopupServiceWithoutConstructor(), $status))->toBeFalse();
})->with(['GOT_RESOURCE', 'EXPIRED', 'CANCELLED', null]);
