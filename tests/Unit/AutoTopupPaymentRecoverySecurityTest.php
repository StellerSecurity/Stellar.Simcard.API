<?php

use App\Services\EsimAutoTopupPaymentRecoveryService;

function paymentRecoveryServiceWithoutConstructor(): EsimAutoTopupPaymentRecoveryService
{
    $reflection = new ReflectionClass(EsimAutoTopupPaymentRecoveryService::class);

    /** @var EsimAutoTopupPaymentRecoveryService $service */
    $service = $reflection->newInstanceWithoutConstructor();

    return $service;
}

function paymentRecoveryUrlIsSecure(string $url): bool
{
    $method = new ReflectionMethod(EsimAutoTopupPaymentRecoveryService::class, 'isSecureUrl');

    return $method->invoke(paymentRecoveryServiceWithoutConstructor(), $url);
}

it('accepts only valid https payment recovery links', function (): void {
    expect(paymentRecoveryUrlIsSecure('https://checkout.stripe.com/c/pay/cs_test_123'))->toBeTrue()
        ->and(paymentRecoveryUrlIsSecure('http://checkout.stripe.com/c/pay/cs_test_123'))->toBeFalse()
        ->and(paymentRecoveryUrlIsSecure('javascript:alert(1)'))->toBeFalse()
        ->and(paymentRecoveryUrlIsSecure('https:///missing-host'))->toBeFalse();
});
