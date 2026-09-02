<?php

use App\Models\Simcard;
use App\Services\Esim\EsimaccessWebhookService;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\EsimAutoTopupService;
use App\Services\SimcardActionLinkService;
use App\Services\VirtualEsimQuotaService;

function webhookServiceWithoutConstructor(): EsimaccessWebhookService
{
    $reflection = new ReflectionClass(EsimaccessWebhookService::class);

    /** @var EsimaccessWebhookService $service */
    $service = $reflection->newInstanceWithoutConstructor();

    return $service;
}

function validityExpiryPromptIsRelevant(
    ?string $providerStatus,
    ?string $localState = null,
    ?string $smdpStatus = null,
): bool {
    $simcard = new Simcard;
    $simcard->forceFill([
        'esim_status' => $providerStatus,
        'smdp_status' => $smdpStatus,
        'state' => $localState,
    ]);

    $method = new ReflectionMethod(EsimaccessWebhookService::class, 'shouldSendValidityExpiryPrompt');

    return $method->invoke(webhookServiceWithoutConstructor(), $simcard);
}

it('sends validity expiry prompts only for provider-confirmed in-use esims', function (
    string $providerStatus,
    bool $expected,
): void {
    expect(validityExpiryPromptIsRelevant($providerStatus, 'active'))->toBe($expected);
})->with([
    'in use' => ['IN_USE', true],
    'case-insensitive in use' => ['in_use', true],
    'not yet activated' => ['GOT_RESOURCE', false],
    'used up' => ['USED_UP', false],
    'suspended' => ['SUSPENDED', false],
    'expired' => ['EXPIRED', false],
    'cancelled' => ['CANCELLED', false],
    'revoked' => ['REVOKED', false],
    'archived' => ['ARCHIVED', false],
]);

it('uses active local state only when provider status is unavailable', function (): void {
    expect(validityExpiryPromptIsRelevant(null, 'active'))->toBeTrue()
        ->and(validityExpiryPromptIsRelevant('', 'OK'))->toBeFalse()
        ->and(validityExpiryPromptIsRelevant(null, 'pending'))->toBeFalse();
});

it('suppresses expiry prompts when an otherwise active profile is off the device', function (
    string $smdpStatus,
): void {
    expect(validityExpiryPromptIsRelevant('IN_USE', 'active', $smdpStatus))->toBeFalse();
})->with(['DISABLED', 'disabled', 'DELETED', 'deleted']);

it('reuses the provider lifecycle lookup for expiry message context', function (): void {
    $provider = Mockery::mock(EsimProvider::class);
    $provider->shouldReceive('queryEsim')
        ->once()
        ->with('order-123', 'iccid-123', 'primary')
        ->andReturn([
            'obj' => [
                'esimList' => [[
                    'esimStatus' => 'IN_USE',
                    'smdpStatus' => 'ENABLED',
                ]],
            ],
        ]);

    $service = new EsimaccessWebhookService(
        Mockery::mock(EsimCryptoService::class),
        $provider,
        Mockery::mock(SimcardActionLinkService::class),
        Mockery::mock(EsimMarketingRefundOfferService::class),
        Mockery::mock(EsimAutoTopupService::class),
        Mockery::mock(VirtualEsimQuotaService::class),
    );
    $simcard = new Simcard;
    $simcard->forceFill(['provider_account' => 'primary']);
    $method = new ReflectionMethod(EsimaccessWebhookService::class, 'providerResponseForWebhook');

    $first = $method->invoke($service, 'order-123', 'iccid-123', $simcard, true);
    $second = $method->invoke($service, 'order-123', 'iccid-123', $simcard);

    expect($first)->toBe($second)
        ->and(data_get($first, 'obj.esimList.0.smdpStatus'))->toBe('ENABLED');
});
