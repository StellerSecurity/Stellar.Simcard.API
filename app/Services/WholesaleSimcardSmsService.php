<?php

namespace App\Services;

use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use DomainException;
use RuntimeException;

class WholesaleSimcardSmsService
{
    public function __construct(
        private readonly EsimProvider $provider,
        private readonly EsimCryptoService $crypto,
        private readonly SimcardService $simcards,
    ) {}

    /**
     * Send one reseller-authored service message to an eSIM.
     *
     * The caller must prove the complete immutable wholesale order identity.
     * This prevents the shared internal Basic credential plus a guessed SIM ID
     * from becoming enough to message an unrelated profile.
     */
    public function send(
        string $planId,
        string $commerceOrderId,
        string $commerceOrderItemId,
        int $commerceUnit,
        string $message,
    ): bool {
        $simcard = Simcard::query()
            ->where('plan_id_hash', $this->crypto->derivePlanHash($planId))
            ->where('commerce_order_id', $commerceOrderId)
            ->where('commerce_order_item_id', $commerceOrderItemId)
            ->where('commerce_unit', $commerceUnit)
            ->first();

        if (! $simcard instanceof Simcard) {
            return false;
        }

        if ($simcard->isLocallyRetired()) {
            throw new DomainException('SMS is unavailable for a retired eSIM.');
        }

        if (empty($simcard->iccid_enc)) {
            $simcard = $this->simcards->ensureProviderIccid($simcard, $planId);
        }

        $iccid = trim($this->crypto->decryptSensitiveValue((string) $simcard->iccid_enc));
        if (preg_match('/^\d{10,32}$/', $iccid) !== 1) {
            throw new RuntimeException('The stored provider eSIM identity is invalid.');
        }

        $account = in_array((string) $simcard->provider_account, ['primary', 'legacy'], true)
            ? (string) $simcard->provider_account
            : 'legacy';

        $response = $this->provider->sendSms($iccid, $message, $account);
        $success = $response['success'] ?? data_get($response, 'obj.success');

        if (
            $success !== null
            && filter_var($success, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false
        ) {
            throw new DomainException('The carrier rejected SMS delivery for this eSIM.');
        }

        // Deliberately discard the remaining provider response. It may contain
        // internal identifiers and is not part of the reseller-facing contract.

        return true;
    }
}
