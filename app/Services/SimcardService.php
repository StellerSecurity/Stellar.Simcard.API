<?php

namespace App\Services;

use App\Exceptions\SimcardOwnershipConflictException;
use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\Esim\SimcardUserReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SimcardService
{
    public function __construct(
        private readonly EsimProvider $provider,
        private readonly EsimCryptoService $crypto,
        private readonly EsimMarketingRefundOfferService $marketingRefundOffer,
        private readonly SimcardUserReferenceService $userReferences,
    ) {}

    /** Fetch plan list from provider */
    public function listPlans(array $filters = []): array
    {
        return $this->provider->listPlans($filters);
    }

    /** Create eSIM order using a client-side generated plan_id (idempotent per plan_id_hash) */
    public function orderEsim(
        ?int $userId,
        ?string $accountRef,
        string $packageCode,
        string $planId,
        ?string $email = null,
        ?string $emailSource = 'order',
        ?string $commerceOrderId = null,
        ?string $commerceOrderItemId = null,
        ?int $commerceUnit = null,
        ?string $idempotencyKey = null
    ): Simcard {

        $planId = preg_replace('/\s+/', '', (string) $planId);
        $planIdHash = $this->crypto->derivePlanHash($planId);
        $commerceOrderId = $this->normalizeNullableIdentifier($commerceOrderId);
        $commerceOrderItemId = $this->normalizeNullableIdentifier($commerceOrderItemId);
        $commerceUnit = $commerceUnit !== null && $commerceUnit > 0 ? $commerceUnit : null;
        $idempotencyKey = $this->normalizeNullableIdentifier($idempotencyKey);

        return DB::transaction(function () use ($planIdHash, $userId, $accountRef, $packageCode, $planId, $email, $emailSource, $commerceOrderId, $commerceOrderItemId, $commerceUnit, $idempotencyKey) {
            // If Commerce retries the same paid order item, do not create a second provider order.
            $existing = $this->findExistingSimcardForOrderRequest(
                planIdHash: $planIdHash,
                commerceOrderId: $commerceOrderId,
                commerceOrderItemId: $commerceOrderItemId,
                commerceUnit: $commerceUnit,
                idempotencyKey: $idempotencyKey,
            );

            if ($existing) {
                $this->storeEmailOnSimcard($existing, $email, $emailSource);
                $this->attachCommerceIdempotencyMetadata($existing, $commerceOrderId, $commerceOrderItemId, $commerceUnit, $idempotencyKey);
                $this->attachUserReference($existing, $userId, 'purchase');

                return $existing;
            }

            $order = $this->provider->createOrder($packageCode, 'primary');

            $externalOrderIdEnc = $this->crypto->encryptForPlan(
                $planId,
                $order->externalOrderId
            );

            $externalOrderIdHash = $this->crypto->deriveExternalOrderHash($order->externalOrderId);

            $simcard = Simcard::create([
                'id'                    => (string) Str::uuid(),
                'plan_id_hash'          => $planIdHash,
                'provider'              => 'esimaccess',
                'provider_account'      => 'primary',
                'package_code'          => $packageCode,
                'external_order_id_enc'  => $externalOrderIdEnc,
                'external_order_id_hash' => $externalOrderIdHash,
                'state'                  => 'pending',
                'user_ref'               => $userId !== null ? $this->userReferences->derive($userId) : null,
                'user_ref_version'       => $userId !== null ? $this->userReferences->currentVersion() : null,
                'user_linked_at'         => $userId !== null ? now() : null,
                'user_link_source'       => $userId !== null ? 'purchase' : null,
                'commerce_order_id'      => $commerceOrderId,
                'commerce_order_item_id' => $commerceOrderItemId,
                'commerce_unit'          => $commerceUnit,
                'idempotency_key'        => $idempotencyKey,
                'purchased_on'           => now()->toDateString(),
            ]);

            $this->storeEmailOnSimcard($simcard, $email, $emailSource);

            return $simcard;
        });
    }

    /** Order and return install payload (AC) when available */
    public function orderAndGetInstallInfo(
        ?int $userId,
        ?string $accountRef,
        string $packageCode,
        string $planId,
        ?string $email = null,
        ?string $emailSource = 'order',
        ?string $commerceOrderId = null,
        ?string $commerceOrderItemId = null,
        ?int $commerceUnit = null,
        ?string $idempotencyKey = null
    ): array {
        $simcard = $this->orderEsim(
            userId: $userId,
            accountRef: $accountRef,
            packageCode: $packageCode,
            planId: $planId,
            email: $email,
            emailSource: $emailSource,
            commerceOrderId: $commerceOrderId,
            commerceOrderItemId: $commerceOrderItemId,
            commerceUnit: $commerceUnit,
            idempotencyKey: $idempotencyKey,
        );

        $install = $this->fetchInstallInfoWithRetry($planId);

        return [
            'simcard' => $simcard,
            'install' => $install,
        ];
    }

    /** Query provider for usage/status for a given plan_id */
    public function queryStatusByPlanId(string $planId): ?array
    {

        $planIdHash = $this->crypto->derivePlanHash($planId);

        $simcard = Simcard::where('plan_id_hash', $planIdHash)->first();

        if (!$simcard) {
            return null;
        }

        $externalOrderId = $this->crypto->decryptForPlan(
            $planId,
            $simcard->external_order_id_enc
        );


        $provider = $this->provider->queryOrder($externalOrderId, $this->preferredProviderAccount($simcard));

        // Extract the first eSIM entry if present.
        $esim = $provider['obj']['esimList'][0] ?? null;

        // Build a minimal, safe payload for clients (usage/status only).
        $safeProvider = [
            'expires_at'      => $esim['expiredTime'] ?? null,
            'total_bytes'     => $esim['totalVolume'] ?? null,
            'used_bytes'      => $esim['orderUsage'] ?? null,
            'remaining_bytes' => (isset($esim['totalVolume'], $esim['orderUsage']) && is_numeric($esim['totalVolume']) && is_numeric($esim['orderUsage']))
                ? max(0, (int) $esim['totalVolume'] - (int) $esim['orderUsage'])
                : null,
            'esim_status'     => $esim['esimStatus'] ?? null,
            'smdp_status'     => $esim['smdpStatus'] ?? null,
            'esim_tran_no'    => $esim['esimTranNo'] ?? null,
            'location_codes'  => $esim['packageList'][0]['locationCode'] ?? null,
        ];

        $isInUse = strtoupper(trim((string) ($safeProvider['esim_status'] ?? ''))) === 'IN_USE';
        $hasUsage = is_numeric($safeProvider['used_bytes'] ?? null)
            && (int) $safeProvider['used_bytes'] > 0;

        if ($isInUse || $hasUsage) {
            $this->marketingRefundOffer->handleUsageDetected($simcard);
            $simcard->refresh();
        }

        return [
            'simcard'  => $simcard,
            'provider' => $safeProvider,
        ];
    }

    /**
     * Return safe simcard metadata for one verified Stellar user.
     * Raw user IDs and derived user references never leave this service.
     */
    public function listByUserId(int $userId): array
    {
        $userReferences = array_values($this->userReferences->deriveAll($userId));

        return Simcard::query()
            ->where(function ($query) use ($userReferences, $userId): void {
                $query->whereIn('user_ref', $userReferences);

                // Transitional compatibility for verified legacy rows. user_id=1 is
                // deliberately excluded because anonymous orders were assigned to 1.
                if ($userId !== 1) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->orderByDesc('purchased_on')
            ->orderBy('id')
            ->get()
            ->map(fn (Simcard $simcard): array => $this->safeUserSimcardPayload($simcard))
            ->values()
            ->all();
    }

    /**
     * Attach an existing eSIM to a verified Stellar user.
     * The plan_id acts as the private possession proof and is never stored.
     */
    public function assignUserByPlanId(
        string $planId,
        int $userId,
        string $source = 'manual_claim'
    ): array {
        $planIdHash = $this->crypto->derivePlanHash($planId);

        return DB::transaction(function () use ($planIdHash, $userId, $source): array {
            $simcard = Simcard::query()
                ->where('plan_id_hash', $planIdHash)
                ->lockForUpdate()
                ->first();

            if (! $simcard) {
                return ['status' => 'not_found', 'simcard' => null];
            }

            $alreadyAssigned = $simcard->user_ref !== null
                && $this->userReferences->matches($simcard->user_ref, $userId, $simcard->user_ref_version);

            $this->attachUserReference($simcard, $userId, $source);

            return [
                'status' => $alreadyAssigned ? 'already_assigned' : 'assigned',
                'simcard' => $this->safeUserSimcardPayload($simcard->fresh()),
            ];
        });
    }

    /** Detach one eSIM only when it belongs to the verified Stellar user. */
    public function detachUserByPlanId(string $planId, int $userId): array
    {
        $planIdHash = $this->crypto->derivePlanHash($planId);
        return DB::transaction(function () use ($planIdHash, $userId): array {
            $simcard = Simcard::query()
                ->where('plan_id_hash', $planIdHash)
                ->lockForUpdate()
                ->first();

            if (! $simcard) {
                return ['status' => 'not_found', 'simcard' => null];
            }

            if ($simcard->user_ref === null) {
                $legacyUserId = $simcard->user_id !== null ? (int) $simcard->user_id : null;

                if ($legacyUserId !== null && $legacyUserId !== 1 && $legacyUserId !== $userId) {
                    throw new SimcardOwnershipConflictException(
                        'The eSIM is assigned to another user.'
                    );
                }

                if ($legacyUserId === $userId && $userId !== 1) {
                    $this->clearUserReference($simcard);

                    return [
                        'status' => 'detached',
                        'simcard' => $this->safeUserSimcardPayload($simcard->fresh()),
                    ];
                }

                return [
                    'status' => 'already_detached',
                    'simcard' => $this->safeUserSimcardPayload($simcard),
                ];
            }

            if (! $this->userReferences->matches(
                $simcard->user_ref,
                $userId,
                $simcard->user_ref_version,
            )) {
                throw new SimcardOwnershipConflictException(
                    'The eSIM is assigned to another user.'
                );
            }

            $this->clearUserReference($simcard);

            return [
                'status' => 'detached',
                'simcard' => $this->safeUserSimcardPayload($simcard->fresh()),
            ];
        });
    }

    /** Detach all eSIM associations for account deletion/privacy workflows. */
    public function detachAllForUserId(int $userId): int
    {
        $userReferences = array_values($this->userReferences->deriveAll($userId));

        return Simcard::query()
            ->where(function ($query) use ($userReferences, $userId): void {
                $query->whereIn('user_ref', $userReferences);

                if ($userId !== 1) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->update([
                'user_id' => null,
                'user_ref' => null,
                'user_ref_version' => null,
                'user_linked_at' => null,
                'user_link_source' => null,
            ]);
    }

    private function attachUserReference(
        Simcard $simcard,
        ?int $userId,
        string $source
    ): void {
        if ($userId === null) {
            return;
        }

        $version = $this->userReferences->currentVersion();
        $reference = $this->userReferences->derive($userId, $version);
        $changed = false;

        if ($simcard->user_ref === null && $simcard->user_id !== null) {
            $legacyUserId = (int) $simcard->user_id;

            if ($legacyUserId !== 1 && $legacyUserId !== $userId) {
                throw new SimcardOwnershipConflictException(
                    'The eSIM is already assigned to another user.'
                );
            }
        }

        if ($simcard->user_ref !== null) {
            if (! $this->userReferences->matches(
                $simcard->user_ref,
                $userId,
                $simcard->user_ref_version,
            )) {
                throw new SimcardOwnershipConflictException(
                    'The eSIM is already assigned to another user.'
                );
            }

            // Transparently rotate a verified reference to the current key version.
            if ($simcard->user_ref_version !== $version || ! hash_equals($simcard->user_ref, $reference)) {
                $simcard->user_ref = $reference;
                $simcard->user_ref_version = $version;
                $changed = true;
            }
        } else {
            $simcard->user_ref = $reference;
            $simcard->user_ref_version = $version;
            $simcard->user_linked_at = now();
            $simcard->user_link_source = $source;
            $changed = true;
        }

        // Remove any legacy raw user identifier when an association is confirmed.
        if ($simcard->user_id !== null) {
            $simcard->user_id = null;
            $changed = true;
        }

        if ($changed) {
            $simcard->save();
        }
    }

    private function clearUserReference(Simcard $simcard): void
    {
        $simcard->user_id = null;
        $simcard->user_ref = null;
        $simcard->user_ref_version = null;
        $simcard->user_linked_at = null;
        $simcard->user_link_source = null;
        $simcard->save();
    }

    private function safeUserSimcardPayload(Simcard $simcard): array
    {
        return [
            'id' => $simcard->id,
            'state' => $simcard->state,
            'provider' => $simcard->provider,
            'package_code' => $simcard->package_code,
            'iccid_last4' => $simcard->iccid_last4,
            'esim_status' => $simcard->esim_status,
            'smdp_status' => $simcard->smdp_status,
            'data_status' => $simcard->data_status,
            'validity_status' => $simcard->validity_status,
            'total_bytes' => $simcard->total_volume,
            'used_bytes' => $simcard->order_usage,
            'remaining_bytes' => $simcard->remaining_volume,
            'remaining_validity' => $simcard->remaining_validity,
            'expires_at' => $simcard->expires_at?->toIso8601String(),
            'activated_at' => $simcard->activated_at?->toIso8601String(),
            'purchased_on' => $simcard->purchased_on?->format('Y-m-d'),
        ];
    }

    private function findExistingSimcardForOrderRequest(
        string $planIdHash,
        ?string $commerceOrderId,
        ?string $commerceOrderItemId,
        ?int $commerceUnit,
        ?string $idempotencyKey
    ): ?Simcard {
        if ($idempotencyKey !== null) {
            $existing = Simcard::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        if ($commerceOrderId !== null && $commerceOrderItemId !== null && $commerceUnit !== null) {
            $existing = Simcard::query()
                ->where('commerce_order_id', $commerceOrderId)
                ->where('commerce_order_item_id', $commerceOrderItemId)
                ->where('commerce_unit', $commerceUnit)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Backward-compatible idempotency. Commerce now persists/reuses the same
        // plan_id across queue retries, so this also prevents duplicates.
        return Simcard::query()
            ->where('plan_id_hash', $planIdHash)
            ->lockForUpdate()
            ->first();
    }

    private function attachCommerceIdempotencyMetadata(
        Simcard $simcard,
        ?string $commerceOrderId,
        ?string $commerceOrderItemId,
        ?int $commerceUnit,
        ?string $idempotencyKey
    ): void {
        $changed = false;

        if ($commerceOrderId !== null && empty($simcard->commerce_order_id)) {
            $simcard->commerce_order_id = $commerceOrderId;
            $changed = true;
        }

        if ($commerceOrderItemId !== null && empty($simcard->commerce_order_item_id)) {
            $simcard->commerce_order_item_id = $commerceOrderItemId;
            $changed = true;
        }

        if ($commerceUnit !== null && empty($simcard->commerce_unit)) {
            $simcard->commerce_unit = $commerceUnit;
            $changed = true;
        }

        if ($idempotencyKey !== null && empty($simcard->idempotency_key)) {
            $simcard->idempotency_key = $idempotencyKey;
            $changed = true;
        }

        if ($changed) {
            $simcard->save();
        }
    }

    private function normalizeNullableIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /** Store/update nullable encrypted customer email for service/top-up notifications. */
    public function storeEmailOnSimcard(Simcard $simcard, ?string $email, ?string $emailSource = 'order'): void
    {
        $normalizedEmail = $this->crypto->normalizeEmail($email);

        if ($normalizedEmail === null) {
            return;
        }

        $simcard->email_enc = $this->crypto->encryptEmail($normalizedEmail);
        $simcard->email_hash = $this->crypto->deriveEmailHash($normalizedEmail);
        $simcard->email_opt_in_at = $simcard->email_opt_in_at ?? now();
        $simcard->email_source = $emailSource ?: 'order';
        $simcard->save();
    }

    /** Decrypt customer email for notification sending. Never expose this in normal API responses. */
    public function decryptSimcardEmail(Simcard $simcard): ?string
    {
        if (!$simcard->email_enc) {
            return null;
        }

        return $this->crypto->decryptEmail($simcard->email_enc);
    }

    /** Fetch install payload (AC) with a short retry loop */
    private function fetchInstallInfoWithRetry(string $planId): array
    {
        $planIdHash = $this->crypto->derivePlanHash($planId);

        $simcard = Simcard::where('plan_id_hash', $planIdHash)->firstOrFail();

        $externalOrderId = $this->crypto->decryptForPlan(
            $planId,
            $simcard->external_order_id_enc
        );

        for ($i = 0; $i < 10; $i++) {
            $provider = $this->provider->queryOrder($externalOrderId, $this->preferredProviderAccount($simcard));

            $install = $this->buildInstallPayload($provider);

            // AC is the only thing we need for installation.
            if (!empty($install['ac'])) {
                if ($simcard->state !== 'OK') {
                    $simcard->state = 'OK';
                    $simcard->save();
                }

                return $install;
            }

            usleep(350_000); // 350ms
        }

        return [
            'ac' => null,
            'apn' => null,
        ];
    }

    private function preferredProviderAccount(Simcard $simcard): string
    {
        return in_array($simcard->provider_account, ['primary', 'legacy'], true)
            ? $simcard->provider_account
            : 'legacy';
    }

    /** Build install payload from provider response */
    private function buildInstallPayload(array $provider): array
    {
        $esim = $provider['obj']['esimList'][0] ?? null;

        if (!is_array($esim)) {
            return [
                'ac' => null,
                'apn' => null,
            ];
        }

        return [
            'ac' => $esim['ac'] ?? null,
            'apn' => $this->extractApn($esim),
        ];
    }

    private function extractApn(array $esim): ?string
    {
        $candidates = [
            $esim['apn'] ?? null,
            $esim['apnValue'] ?? null,
            $esim['accessPointName'] ?? null,
            $esim['installation']['apn'] ?? null,
            $esim['install']['apn'] ?? null,
            $esim['profile']['apn'] ?? null,
            $esim['packageList'][0]['apn'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
