<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardActionLink;
use App\Services\Esim\EsimCryptoService;
use Illuminate\Support\Str;
use RuntimeException;

class SimcardActionLinkService
{
    private const TOPUP_ACTION = 'topup';
    private const SLUG_LENGTH = 12;
    private const EXPIRES_DAYS = 14;
    private const MAX_SLUG_GENERATION_ATTEMPTS = 8;
    private const SLUG_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function __construct(
        private readonly EsimCryptoService $crypto,
    ) {}

    public function createTopupUrl(Simcard $simcard, string $reason, ?string $webhookEventId = null): string
    {
        [$slug, $slugHash] = $this->createUniqueSlugAndHash();

        SimcardActionLink::create([
            'id' => (string) Str::uuid(),
            'simcard_id' => $simcard->id,
            'action' => self::TOPUP_ACTION,
            'token_hash' => $slugHash,
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
            'metadata_redacted' => array_filter([
                'reason' => $reason,
                'webhook_event_id' => $webhookEventId,
                'expires_after_days' => self::EXPIRES_DAYS,
                'slug_length' => self::SLUG_LENGTH,
            ], static fn ($value) => $value !== null),
        ]);

        return $this->topupBaseUrl() . '/' . rawurlencode($slug);
    }

    private function topupBaseUrl(): string
    {
        return rtrim((string) config('services.stellar_data.topup_url', 'https://data.stellarsecurity.com/topup'), '/');
    }

    /**
     * Creates a compact public slug and stores only its HMAC hash.
     *
     * A 12-character base62 slug gives about 71 bits of entropy, which keeps SMS URLs short
     * while remaining non-enumerable for a 14-day action link.
     *
     * @return array{0: string, 1: string}
     */
    private function createUniqueSlugAndHash(): array
    {
        for ($attempt = 0; $attempt < self::MAX_SLUG_GENERATION_ATTEMPTS; $attempt++) {
            $slug = $this->newBase62Slug();
            $hash = $this->crypto->deriveActionLinkTokenHash($slug);

            if (! SimcardActionLink::query()->where('token_hash', $hash)->exists()) {
                return [$slug, $hash];
            }
        }

        throw new RuntimeException('Failed to create a unique top-up action link.');
    }

    private function newBase62Slug(): string
    {
        $alphabetLength = strlen(self::SLUG_ALPHABET);
        $slug = '';

        for ($i = 0; $i < self::SLUG_LENGTH; $i++) {
            $slug .= self::SLUG_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $slug;
    }
}
