<?php

use App\Services\Esim\EsimCryptoService;
function installPayloadCrypto(): EsimCryptoService
{
    $reflection = new ReflectionClass(EsimCryptoService::class);
    /** @var EsimCryptoService $crypto */
    $crypto = $reflection->newInstanceWithoutConstructor();

    foreach ([
        'hashKey' => 'test-hash-key-that-is-not-used-for-decryption',
        'masterKey' => 'test-master-key-for-plan-derived-encryption',
    ] as $property => $value) {
        $refProperty = $reflection->getProperty($property);
        $refProperty->setValue($crypto, $value);
    }

    return $crypto;
}

it('encrypts install credentials with the exact plan id', function (): void {
    $crypto = installPayloadCrypto();
    $planId = '1234123412341234';
    $payload = json_encode([
        'lpa' => 'LPA:1$rsp-eu.example.test$ABC123',
        'apn' => 'bicsapn',
    ], JSON_THROW_ON_ERROR);

    $ciphertext = $crypto->encryptForPlan($planId, $payload);

    expect($ciphertext)->not->toContain('LPA:1$')
        ->and($ciphertext)->not->toContain('bicsapn')
        ->and($crypto->decryptForPlan($planId, $ciphertext))->toBe($payload);
});

it('cannot decrypt install credentials with another plan id', function (): void {
    $crypto = installPayloadCrypto();
    $ciphertext = $crypto->encryptForPlan(
        '1234123412341234',
        '{"lpa":"LPA:1$rsp-eu.example.test$ABC123"}'
    );

    expect(fn () => $crypto->decryptForPlan('9999999999999999', $ciphertext))
        ->toThrow(\RuntimeException::class, 'Failed to decrypt value.');
});
