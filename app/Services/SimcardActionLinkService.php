<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardActionLink;
use App\Services\Esim\EsimCryptoService;
use Illuminate\Support\Str;

class SimcardActionLinkService
{
    private const TOPUP_ACTION = 'topup';
    private const TOKEN_BYTES = 32;
    private const EXPIRES_DAYS = 14;

    public function __construct(
        private readonly EsimCryptoService $crypto,
    ) {}

    public function createTopupUrl(Simcard $simcard, string $reason, ?string $webhookEventId = null): string
    {
        $token = $this->newUrlSafeToken();

        SimcardActionLink::create([
            'id' => (string) Str::uuid(),
            'simcard_id' => $simcard->id,
            'action' => self::TOPUP_ACTION,
            'token_hash' => $this->crypto->deriveActionLinkTokenHash($token),
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
            'metadata_redacted' => array_filter([
                'reason' => $reason,
                'webhook_event_id' => $webhookEventId,
                'expires_after_days' => self::EXPIRES_DAYS,
            ], static fn ($value) => $value !== null),
        ]);

        return $this->topupBaseUrl() . '?t=' . rawurlencode($token);
    }

    private function topupBaseUrl(): string
    {
        return rtrim((string) config('services.stellar_data.topup_url', 'https://data.stellarsecurity.com/topup'), '/');
    }

    private function newUrlSafeToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }
}
