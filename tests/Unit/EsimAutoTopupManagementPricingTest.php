<?php

use App\Models\Simcard;
use App\Services\EsimAutoTopupManagementService;

function buildAutoTopupStatus(array $commerce): array
{
    $reflection = new ReflectionClass(EsimAutoTopupManagementService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(EsimAutoTopupManagementService::class, 'buildStatus');

    return $method->invoke($service, new Simcard(), null, $commerce, null);
}

it('preserves the complete commerce auto top-up pricing snapshot', function (): void {
    $status = buildAutoTopupStatus([
        'amount_cents' => 1000,
        'service_fee_cents' => 15,
        'total_amount_cents' => 1015,
        'service_fee_basis_points' => 150,
        'service_fee_type' => 'percentage',
        'currency' => 'eur',
    ]);

    expect($status['amount_cents'])->toBe(1000)
        ->and($status['service_fee_cents'])->toBe(15)
        ->and($status['total_amount_cents'])->toBe(1015)
        ->and($status['service_fee_basis_points'])->toBe(150)
        ->and($status['service_fee_type'])->toBe('percentage')
        ->and($status['currency'])->toBe('EUR');
});

it('derives the payable total when an older commerce response omits it', function (): void {
    $status = buildAutoTopupStatus([
        'amount_cents' => 99,
        'service_fee_cents' => 100,
        'currency' => 'EUR',
    ]);

    expect($status['total_amount_cents'])->toBe(199);
});

it('keeps the base amount as a compatibility fallback without fee metadata', function (): void {
    $status = buildAutoTopupStatus([
        'amount_cents' => 538,
        'currency' => 'EUR',
    ]);

    expect($status['total_amount_cents'])->toBe(538)
        ->and($status['service_fee_cents'])->toBeNull();
});
