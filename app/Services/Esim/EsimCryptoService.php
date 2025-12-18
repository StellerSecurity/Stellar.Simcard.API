<?php

namespace App\Services\Esim;

use RuntimeException;

class EsimCryptoService
{
    private const PLAN_ID_PATTERN = '/^\d{16}$/';

    // Keep v1.
    private const PLAN_HASH_VERSION = 'v1';

    // Hardcoded tuning (watch CPU/latency).
    private const PLAN_HASH_PBKDF2_ITERS = 300_000;

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
     * Encrypts a plaintext value using a per-plan key derived from the plan_id.
     * Uses AES-256-GCM with 96-bit IV and 16-byte authentication tag.
     */
    public function encryptForPlan(string $planId, string $plaintext): string
    {
        $planId = $this->normalizeAndValidatePlanId($planId);

        $key = $this->derivePlanKey($planId);
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

    /**
     * Decrypts a ciphertext using the per-plan key derived from the plan_id.
     */
    public function decryptForPlan(string $planId, string $encodedCiphertext): string
    {
        $planId = $this->normalizeAndValidatePlanId($planId);

        $key  = $this->derivePlanKey($planId);
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
     * Derives the per-plan data encryption key: DEK = HMAC(K_MASTER, plan_id)
     */
    private function derivePlanKey(string $planId): string
    {
        return hash_hmac('sha256', $planId, $this->masterKey, true);
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
