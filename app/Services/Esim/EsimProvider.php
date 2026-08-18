<?php

namespace App\Services\Esim;

interface EsimProvider
{
    public function listPlans(array $filters = [], string $account = 'primary'): array;

    public function createOrder(string $packageCode, string $account = 'primary'): EsimProviderOrder;

    public function queryOrder(string $externalOrderId, string $account = 'primary'): array;

    public function queryEsim(?string $externalOrderId = null, ?string $iccid = null, string $account = 'primary'): array;

    /**
     * Resolve which configured eSIMAccess account owns an existing eSIM.
     * This performs read-only queries and may safely try the alternate account.
     */
    public function resolveAccountForEsim(?string $externalOrderId = null, ?string $iccid = null, string $preferredAccount = 'legacy'): string;

    public function sendSms(string $iccid, string $message, string $account = 'primary'): array;

    /**
     * Apply a paid top-up exactly once against the already-resolved account.
     * Callers must never blindly retry this method with another account.
     */
    public function topup(string $iccid, string $packageCode, string $transactionId, string $account = 'primary'): array;
}
