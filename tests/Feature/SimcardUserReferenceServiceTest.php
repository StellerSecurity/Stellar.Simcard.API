<?php

use App\Services\Esim\SimcardUserReferenceService;
beforeEach(function (): void {
    config()->set('esim.user_reference.current_version', 1);
    config()->set('esim.user_reference.keys.1', str_repeat('a', 64));
});

it('derives a deterministic versioned keyed user reference', function (): void {
    $service = app(SimcardUserReferenceService::class);

    $first = $service->derive(7345);
    $second = $service->derive(7345);

    expect($first)
        ->toBe($second)
        ->toStartWith('v1:')
        ->toHaveLength(67)
        ->not->toContain('7345');
});

it('derives different references for different users', function (): void {
    $service = app(SimcardUserReferenceService::class);

    expect($service->derive(7345))
        ->not->toBe($service->derive(7346));
});

it('supports key versions for rotation and matching', function (): void {
    config()->set('esim.user_reference.current_version', 2);
    config()->set('esim.user_reference.keys.2', str_repeat('b', 64));

    $service = app(SimcardUserReferenceService::class);
    $legacy = $service->derive(7345, 1);

    expect($service->matches($legacy, 7345, 1))->toBeTrue()
        ->and($service->derive(7345, 2))->toStartWith('v2:')
        ->and($service->deriveAll(7345))->toHaveKeys([1, 2]);
});

it('rejects weak or missing user reference keys', function (): void {
    config()->set('esim.user_reference.keys.1', 'short');

    expect(fn () => app(SimcardUserReferenceService::class)->derive(7345))
        ->toThrow(RuntimeException::class);
});
