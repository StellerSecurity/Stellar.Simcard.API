<?php

namespace App\Services\Esim;

use RuntimeException;

class EsimCryptoService
{
    private const PLAN_ID_PATTERN = '/^\d{16}$/';

    // Keep v1.
    private const PLAN_HASH_VERSION = 'v1';

    // Hardcoded tuning (watch CPU/latency).
    private const PLAN_HASH_PBKDF2_ITERS = 800_000;

    private string $hashKey;
    private string $masterKey;

    public function __construct()
    {
        $this->hashKey   = (string) (config('esim.crypto.hash_key') ?? '');
        $this->masterKey = (string) (config('esim.crypto.master_key') ?? '');

        if ($this->hashKey === '' || $this->masterKey === '') {
            throw new RuntimeException('ESIM crypto keys are missing.');
        }
    }

    /**
     * Derives a stable, non-reversible hash of the plan_id for DB lookups.
     * The plan_id itself is never stored.
     *
     * Note: This is intentionally slow to resist offline brute force if DB + hash_key leak.
     */
    public function derivePlanHash(string $planId): string
    {
        $planId = $this->normalizeAndValidatePlanId($planId);

        $hex = hash_pbkdf2(
            algo: 'sha256',
            password: $planId,
            salt: $this->hashKey,
            iterations: self::PLAN_HASH_PBKDF2_ITERS,
            length: 32,
            binary: false
        );

        return self::PLAN_HASH_VERSION . ':' . $hex;
    }

    /**
     * Derives a stable, non-reversible hash of the provider order number for webhook lookups.
     */
    public function deriveExternalOrderHash(string $externalOrderId): string
    {
        return $this->deriveSensitiveValueHash($externalOrderId, 'external_order_id');
    }

    /**
     * Derives a stable, non-reversible hash of the ICCID for matching/audit without plaintext storage.
     */
    public function deriveIccidHash(string $iccid): string
    {
        return $this->deriveSensitiveValueHash($iccid, 'iccid');
    }


    /**
     * Normalizes an optional customer email before encryption/hash storage.
     */
    public function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        if ($email === '') {
            return null;
        }

        return strtolower($email);
    }

    /**
     * Derives a stable, non-reversible hash of an email for lookups/dedupe.
     * The plaintext email is never stored.
     */
    public function deriveEmailHash(string $email): string
    {
        $email = $this->normalizeEmail($email);

        if ($email === null) {
            throw new RuntimeException('Cannot hash an empty email.');
        }

        return $this->deriveSensitiveValueHash($email, 'simcard_customer_email');
    }

    /**
     * Encrypts a normalized email using the recoverable sensitive-value key.
     */
    public function encryptEmail(string $email): string
    {
        $email = $this->normalizeEmail($email);

        if ($email === null) {
            throw new RuntimeException('Cannot encrypt an empty email.');
        }

        return $this->encryptSensitiveValue($email);
    }

    /**
     * Decrypts an email encrypted with encryptEmail().
     */
    public function decryptEmail(string $encodedCiphertext): string
    {
        return $this->decryptSensitiveValue($encodedCiphertext);
    }

    /**
     * Derives a stable, non-reversible hash of provider transaction identifiers.
     */
    public function deriveTransactionHash(string $transactionId): string
    {
        return $this->deriveSensitiveValueHash($transactionId, 'transaction_id');
    }

    /**
     * Derives a stable, non-reversible hash for one-time action-link tokens.
     * The raw token must never be stored.
     */
    public function deriveActionLinkTokenHash(string $token): string
    {
        return $this->deriveSensitiveValueHash($token, 'simcard_action_link_token');
    }

    /**
     * Encrypts a plaintext value with a master-key-derived AEAD key.
     * Use this only when the value must be recoverable for provider operations.
     */
    public function encryptSensitiveValue(string $plaintext): string
    {
        $plaintext = trim($plaintext);

        if ($plaintext === '') {
            throw new RuntimeException('Cannot encrypt an empty sensitive value.');
        }

        return $this->encryptWithKey($this->deriveMasterDataKey(), $plaintext);
    }

    /**
     * Decrypts a value encrypted with encryptSensitiveValue().
     */
    public function decryptSensitiveValue(string $encodedCiphertext): string
    {
        return $this->decryptWithKey($this->deriveMasterDataKey(), $encodedCiphertext);
    }

    /**
     * Encrypts a plaintext value using a per-plan key derived from the plan_id.
     * Uses AES-256-GCM with 96-bit IV and 16-byte authentication tag.
     */
    public function encryptForPlan(string $planId, string $plaintext): string
    {
        $planId = $this->normalizeAndValidatePlanId($planId);

        return $this->encryptWithKey($this->derivePlanKey($planId), $plaintext);
    }

    /**
     * Decrypts a ciphertext using the per-plan key derived from the plan_id.
     */
    public function decryptForPlan(string $planId, string $encodedCiphertext): string
    {
        $planId = $this->normalizeAndValidatePlanId($planId);

        return $this->decryptWithKey($this->derivePlanKey($planId), $encodedCiphertext);
    }

    /**
     * Returns the last four characters of an identifier for support/debug display.
     */
    public function last4(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        return substr($value, -4);
    }

    private function deriveSensitiveValueHash(string $value, string $purpose): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException('Cannot hash an empty sensitive value.');
        }

        return self::PLAN_HASH_VERSION . ':' . hash_hmac('sha256', $purpose . ':' . $value, $this->hashKey);
    }

    private function deriveMasterDataKey(): string
    {
        return hash_hmac('sha256', 'recoverable-sensitive-provider-values', $this->masterKey, true);
    }

    /**
     * Derives the per-plan data encryption key: DEK = HMAC(K_MASTER, plan_id)
     */
    private function derivePlanKey(string $planId): string
    {
        return hash_hmac('sha256', $planId, $this->masterKey, true);
    }

    private function encryptWithKey(string $key, string $plaintext): string
    {
        $iv  = random_bytes(12); // 96-bit IV

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Failed to encrypt value.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decryptWithKey(string $key, string $encodedCiphertext): string
    {
        $data = base64_decode($encodedCiphertext, true);

        if ($data === false || strlen($data) < 12 + 16 + 1) {
            throw new RuntimeException('Invalid encrypted value format.');
        }

        $iv         = substr($data, 0, 12);
        $tag        = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt value.');
        }

        return $plaintext;
    }

    /**
     * Normalizes and validates the plan_id format.
     */
    private function normalizeAndValidatePlanId(string $planId): string
    {
        $planId = preg_replace('/\s+/', '', $planId) ?? $planId;

        if (!preg_match(self::PLAN_ID_PATTERN, $planId)) {
            throw new RuntimeException('Invalid plan_id format. Expected 16 digits.');
        }

        return $planId;
    }
}
