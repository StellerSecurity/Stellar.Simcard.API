<?php

namespace App\Services;

use App\Exceptions\SimcardOwnershipConflictException;
use App\Models\Simcard;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\EsimProvider;
use App\Services\Esim\SimcardUserReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SimcardService
{
    private ?bool $installStorageAvailable = null;

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
        $this->storeInstallPayload($simcard, $planId, $install);

        return [
            'simcard' => $simcard->fresh(),
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
        $esim = $this->firstProviderEsim($provider);

        // Build the safe usage/status payload plus the installation payload.
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

        $install = $this->buildInstallPayload($provider);
        $this->storeInstallPayload($simcard, $planId, $install);
        $simcard->refresh();
        $install = $this->storedInstallPayload($simcard, $planId);

        $isInUse = strtoupper(trim((string) ($safeProvider['esim_status'] ?? ''))) === 'IN_USE';
        $hasUsage = is_numeric($safeProvider['used_bytes'] ?? null)
            && (int) $safeProvider['used_bytes'] > 0;

        if ($isInUse || $hasUsage) {
            $this->marketingRefundOffer->handleUsageDetected($simcard);
            $simcard->refresh();
        }

        return [
            'simcard'  => $simcard->fresh(),
            'provider' => $safeProvider,
            'install'  => $install,
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

        $result = DB::transaction(function () use ($planIdHash, $userId, $source): array {
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

        if ($result['status'] === 'not_found') {
            return $result;
        }

        // The ownership write is already committed. Installation refresh is best-effort
        // and must never roll back or fail a successful attachment.
        try {
            $this->queryStatusByPlanId($planId);

            $simcard = Simcard::query()->where('plan_id_hash', $planIdHash)->first();
            if ($simcard) {
                $result['simcard'] = $this->safeUserSimcardPayload($simcard);
            }
        } catch (Throwable) {
            // The app can retry the lookup later using the private SIM ID.
        }

        return $result;
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
        // Installation credentials are deliberately excluded from account listings.
        // They can only be decrypted by the explicit plan_id possession-proof query.
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

    /** Fetch install payload with a short retry loop. */
    private function fetchInstallInfoWithRetry(string $planId): array
    {
        $planIdHash = $this->crypto->derivePlanHash($planId);
        $simcard = Simcard::where('plan_id_hash', $planIdHash)->firstOrFail();

        $stored = $this->storedInstallPayload($simcard, $planId);
        if ($this->installPayloadReady($stored)) {
            return $this->withLegacyInstallAliases($stored);
        }

        $externalOrderId = $this->crypto->decryptForPlan(
            $planId,
            $simcard->external_order_id_enc
        );

        $best = $this->emptyInstallPayload();

        for ($i = 0; $i < 10; $i++) {
            $provider = $this->provider->queryOrder(
                $externalOrderId,
                $this->preferredProviderAccount($simcard)
            );

            $install = $this->buildInstallPayload($provider);
            $best = array_replace($best, array_filter(
                $install,
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ));

            if ($this->installPayloadReady($best)) {
                if ($simcard->state !== 'OK') {
                    $simcard->state = 'OK';
                    $simcard->save();
                }

                break;
            }

            usleep(350_000);
        }

        $this->storeInstallPayload($simcard, $planId, $best);

        return $this->withLegacyInstallAliases($best);
    }

    private function preferredProviderAccount(Simcard $simcard): string
    {
        return in_array($simcard->provider_account, ['primary', 'legacy'], true)
            ? $simcard->provider_account
            : 'legacy';
    }

    /** Build a canonical install payload from provider response aliases. */
    private function buildInstallPayload(array $provider): array
    {
        $esim = $this->firstProviderEsim($provider);

        if ($esim === []) {
            return $this->emptyInstallPayload();
        }

        $lpa = $this->normalizeLpa($this->firstString([
            $esim['ac'] ?? null,
            $esim['lpa'] ?? null,
            $esim['activationCode'] ?? null,
            $esim['activation_code'] ?? null,
            $esim['installation']['ac'] ?? null,
            $esim['installation']['lpa'] ?? null,
            $esim['install']['ac'] ?? null,
            $esim['install']['lpa'] ?? null,
        ]));

        return [
            'qr_code_url' => $this->httpsUrl($this->firstString([
                $esim['qrCodeUrl'] ?? null,
                $esim['qr_code_url'] ?? null,
                $esim['qrUrl'] ?? null,
                $esim['qr_url'] ?? null,
                $esim['installation']['qrCodeUrl'] ?? null,
                $esim['install']['qrCodeUrl'] ?? null,
            ])),
            'short_url' => $this->httpsUrl($this->firstString([
                $esim['shortUrl'] ?? null,
                $esim['short_url'] ?? null,
                $esim['installUrl'] ?? null,
                $esim['install_url'] ?? null,
                $esim['downloadUrl'] ?? null,
                $esim['installation']['shortUrl'] ?? null,
                $esim['install']['shortUrl'] ?? null,
            ])),
            'lpa' => $lpa,
            'apn' => $this->extractApn($esim),
        ];
    }

    private function firstProviderEsim(array $provider): array
    {
        foreach ([
            data_get($provider, 'obj.esimList.0'),
            data_get($provider, 'data.obj.esimList.0'),
            data_get($provider, 'data.esimList.0'),
            data_get($provider, 'esimList.0'),
        ] as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function storeInstallPayload(Simcard $simcard, string $planId, array $payload): void
    {
        if (! $this->installStorageAvailable()) {
            return;
        }

        $incoming = $this->canonicalInstallPayload($payload);
        $existing = $this->storedInstallPayload($simcard, $planId);
        $payload = array_replace($existing, array_filter(
            $incoming,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        ));

        if (! $this->hasInstallPayload($payload) || $payload === $existing) {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            return;
        }

        // The ciphertext key is derived from the exact 16-digit plan_id.
        // The database stores only the ciphertext and the non-reversible plan hash.
        $simcard->install_payload_enc = $this->crypto->encryptForPlan($planId, $json);
        $simcard->install_payload_crypto_version = 2;
        $simcard->install_payload_captured_at = now();
        $simcard->save();
    }

    private function storedInstallPayload(Simcard $simcard, string $planId): array
    {
        if (
            ! $this->installStorageAvailable()
            || (int) $simcard->install_payload_crypto_version !== 2
            || ! is_string($simcard->install_payload_enc)
            || $simcard->install_payload_enc === ''
        ) {
            return [];
        }

        try {
            $decoded = json_decode(
                $this->crypto->decryptForPlan($planId, $simcard->install_payload_enc),
                true,
                16,
                JSON_THROW_ON_ERROR
            );

            return is_array($decoded) ? $this->canonicalInstallPayload($decoded) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function installStorageAvailable(): bool
    {
        return $this->installStorageAvailable ??= Schema::hasColumn('simcards', 'install_payload_enc')
            && Schema::hasColumn('simcards', 'install_payload_crypto_version');
    }

    private function canonicalInstallPayload(array $payload): array
    {
        return [
            'qr_code_url' => $this->httpsUrl($this->firstString([
                $payload['qr_code_url'] ?? null,
                $payload['qrCodeUrl'] ?? null,
            ])),
            'short_url' => $this->httpsUrl($this->firstString([
                $payload['short_url'] ?? null,
                $payload['shortUrl'] ?? null,
                $payload['install_url'] ?? null,
            ])),
            'lpa' => $this->normalizeLpa($this->firstString([
                $payload['lpa'] ?? null,
                $payload['ac'] ?? null,
                $payload['activation_code'] ?? null,
            ])),
            'apn' => $this->textValue($payload['apn'] ?? null, 255),
        ];
    }

    private function withLegacyInstallAliases(array $payload): array
    {
        $payload = $this->canonicalInstallPayload($payload);
        $payload['ac'] = $payload['lpa'];

        return $payload;
    }

    private function emptyInstallPayload(): array
    {
        return [
            'qr_code_url' => null,
            'short_url' => null,
            'lpa' => null,
            'apn' => null,
        ];
    }

    private function hasInstallPayload(array $payload): bool
    {
        foreach ($payload as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function installPayloadReady(array $payload): bool
    {
        return ! empty($payload['lpa'])
            && (! empty($payload['qr_code_url']) || ! empty($payload['short_url']));
    }

    private function normalizeLpa(?string $value): ?string
    {
        $value = $this->textValue($value, 4096);
        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, '1$')) {
            $value = 'LPA:'.$value;
        }

        return preg_match('/^LPA:1\$/i', $value) === 1 ? $value : null;
    }

    private function httpsUrl(?string $value): ?string
    {
        $value = $this->textValue($value, 4096);
        if ($value === null || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https'
            ? $value
            : null;
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $value = $this->textValue($value, 4096);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function textValue(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && strlen($value) <= $maxLength ? $value : null;
    }

    private function extractApn(array $esim): ?string
    {
        return $this->firstString([
            $esim['apn'] ?? null,
            $esim['apnValue'] ?? null,
            $esim['accessPointName'] ?? null,
            $esim['installation']['apn'] ?? null,
            $esim['install']['apn'] ?? null,
            $esim['profile']['apn'] ?? null,
            $esim['packageList'][0]['apn'] ?? null,
        ]);
    }
}
