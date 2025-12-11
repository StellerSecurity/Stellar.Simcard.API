<?php

namespace App\Services\Esim;

use RuntimeException;

class EsimCryptoService
{
    private string $hashKey;
    private string $masterKey;

    public function __construct()
    {
        $this->hashKey  = config('esim.crypto.hash_key') ?? '';
        $this->masterKey = config('esim.crypto.master_key') ?? '';

        if ($this->hashKey === '' || $this->masterKey === '') {
            throw new RuntimeException('ESIM crypto keys are missing.');
        }
    }

    /**
     * Derives a stable HMAC-based hash of the plan_id for DB lookups.
     * The plan_id itself is never stored.
     */
    public function derivePlanHash(string $planId): string
    {
        return hash_hmac('sha256', $planId, $this->hashKey);
    }

    /**
     * Encrypts a plaintext value using a per-plan key derived from the plan_id.
     * Uses AES-256-GCM with 96-bit IV and 16-byte authentication tag.
     */
    public function encryptForPlan(string $planId, string $plaintext): string
    {
        $key = $this->derivePlanKey($planId);
        $iv  = random_bytes(12); // 96-bit IV

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

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt value.');
        }

        // Store iv + tag + ciphertext together as base64.
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a ciphertext using the per-plan key derived from the plan_id.
     */
    public function decryptForPlan(string $planId, string $encodedCiphertext): string
    {
        $key  = $this->derivePlanKey($planId);
        $data = base64_decode($encodedCiphertext, true);

        if ($data === false || strlen($data) < 12 + 16) {
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
}
