<?php

use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\Esim\SimcardUserReferenceService;
use App\Services\SimcardService;
use Mockery\MockInterface;

it('normalizes provider installation credentials', function (): void {
    /** @var EsimProvider&MockInterface $provider */
    $provider = Mockery::mock(EsimProvider::class);
    /** @var EsimCryptoService&MockInterface $crypto */
    $crypto = Mockery::mock(EsimCryptoService::class);
    /** @var EsimMarketingRefundOfferService&MockInterface $marketing */
    $marketing = Mockery::mock(EsimMarketingRefundOfferService::class);

    $service = new SimcardService(
        $provider,
        $crypto,
        $marketing,
        new SimcardUserReferenceService(),
    );

    $method = new ReflectionMethod($service, 'buildInstallPayload');

    $payload = $method->invoke($service, [
        'obj' => [
            'esimList' => [[
                'ac' => '1$rsp-eu.simlessly.com$ABC123',
                'qrCodeUrl' => 'https://cdn.example.test/esim/qr.png',
                'shortUrl' => 'https://install.example.test/e/abc123',
                'apn' => 'bicsapn',
            ]],
        ],
    ]);

    expect($payload)->toBe([
        'qr_code_url' => 'https://cdn.example.test/esim/qr.png',
        'short_url' => 'https://install.example.test/e/abc123',
        'lpa' => 'LPA:1$rsp-eu.simlessly.com$ABC123',
        'apn' => 'bicsapn',
    ]);
});

it('rejects non https install urls and malformed activation codes', function (): void {
    $service = new SimcardService(
        Mockery::mock(EsimProvider::class),
        Mockery::mock(EsimCryptoService::class),
        Mockery::mock(EsimMarketingRefundOfferService::class),
        new SimcardUserReferenceService(),
    );

    $method = new ReflectionMethod($service, 'buildInstallPayload');

    $payload = $method->invoke($service, [
        'obj' => [
            'esimList' => [[
                'ac' => 'not-an-lpa',
                'qrCodeUrl' => 'http://example.test/qr.png',
                'shortUrl' => 'javascript:alert(1)',
            ]],
        ],
    ]);

    expect($payload)->toBe([
        'qr_code_url' => null,
        'short_url' => null,
        'lpa' => null,
        'apn' => null,
    ]);
});
