<?php

namespace App\Services\Esim;

interface EsimProvider
{
    public function listPlans(array $filters = []): array;

    public function createOrder(string $packageCode): EsimProviderOrder;

    public function queryOrder(string $externalOrderId): array;

    /**
     * Query eSIM details by provider order number or ICCID.
     * Callers must sanitize/redact the provider response before logging or storing it.
     */
    public function queryEsim(?string $externalOrderId = null, ?string $iccid = null): array;

    public function sendSms(string $iccid, string $message): array;

    /**
     * Apply a paid top-up/recharge package to an existing eSIM ICCID.
     * Callers must sanitize/redact provider responses before logging or storing.
     */
    public function topup(string $iccid, string $packageCode, string $transactionId): array;
}
