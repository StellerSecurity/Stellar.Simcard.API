<?php

namespace App\Services\Esim;

interface EsimProvider
{
    public function listPlans(array $filters = [], string $account = 'primary'): array;

    /**
     * Create one provider eSIM order.
     *
     * $periodNum is only used for provider Daily/Unlimited (dataType=2) plans.
     * Leaving it null preserves the existing fixed-data order contract exactly.
     */
    public function createOrder(
        string $packageCode,
        string $account = 'primary',
        ?int $periodNum = null
    ): EsimProviderOrder;

    public function queryOrder(string $externalOrderId, string $account = 'primary'): array;

    public function queryEsim(?string $externalOrderId = null, ?string $iccid = null, string $account = 'primary'): array;

    /**
     * Resolve which configured eSIMAccess account owns an existing eSIM.
     * This performs read-only queries and may safely try the alternate account.
     */
    public function resolveAccountForEsim(?string $externalOrderId = null, ?string $iccid = null, string $preferredAccount = 'legacy'): string;

    public function sendSms(string $iccid, string $message, string $account = 'primary'): array;

    /** Cancel an unused, uninstalled profile and request the provider refund. */
    public function cancelEsim(string $esimTranNo, string $account = 'primary'): array;

    /** Suspend data service for an allocated eSIM profile. */
    public function suspendEsim(string $iccid, string $account = 'primary'): array;

    /** Restore data service for a previously suspended eSIM profile. */
    public function unsuspendEsim(string $iccid, string $account = 'primary'): array;

    /**
     * Apply a paid top-up exactly once against the already-resolved account.
     * Callers must never blindly retry this method with another account.
     */
    public function topup(string $iccid, string $packageCode, string $transactionId, string $account = 'primary'): array;
}
