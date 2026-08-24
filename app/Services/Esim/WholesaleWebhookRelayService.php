<?php

namespace App\Services\Esim;

use App\Models\Simcard;
use App\Models\WholesaleWebhookRelay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WholesaleWebhookRelayService
{
    private const PROVIDER = 'esimaccess';
    private const MAX_ATTEMPTS = 20;

    public function __construct(
        private readonly EsimCryptoService $crypto,
    ) {}

    /**
     * Capture is intentionally isolated from the existing eSIMAccess webhook
     * business logic. It is called only after Laravel has produced the original
     * webhook response.
     */
    public function capture(Request $request): ?WholesaleWebhookRelay
    {
        if (!$this->configured()) {
            return null;
        }

        $rawBody = (string) $request->getContent();

        if ($rawBody === '') {
            $rawBody = json_encode($request->all(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return WholesaleWebhookRelay::create([
            'id' => (string) Str::uuid(),
            'provider' => self::PROVIDER,
            'payload_encrypted' => Crypt::encryptString($rawBody),
            'content_type' => mb_substr((string) $request->header('Content-Type', 'application/json'), 0, 120),
            'status' => 'pending',
            'attempts' => 0,
            'received_at' => now(),
            'next_attempt_at' => now(),
        ]);
    }

    public function relay(WholesaleWebhookRelay|string $relay): array
    {
        $relay = $relay instanceof WholesaleWebhookRelay
            ? $relay
            : WholesaleWebhookRelay::query()->findOrFail($relay);

        if ($relay->status === 'delivered') {
            return ['status' => 'delivered'];
        }

        if (!$this->configured()) {
            return ['status' => 'disabled'];
        }

        if ($relay->attempts >= self::MAX_ATTEMPTS) {
            $relay->forceFill([
                'status' => 'failed',
                'next_attempt_at' => null,
            ])->save();

            return ['status' => 'failed'];
        }

        $body = Crypt::decryptString((string) $relay->payload_encrypted);
        $context = $this->resolveCommerceContext($body);
        $timestamp = (string) now()->timestamp;
        $signature = $this->signature($timestamp, $relay->id, $context, $body);
        $attempt = $relay->attempts + 1;

        $relay->forceFill([
            'status' => 'delivering',
            'attempts' => $attempt,
            'last_attempt_at' => now(),
            'next_attempt_at' => null,
        ])->save();

        try {
            $headers = [
                'User-Agent' => 'Stellar-Simcard-Webhook-Relay/1.0',
                'Accept' => 'application/json',
                'Content-Type' => $relay->content_type ?: 'application/json',
                'X-Stellar-Relay-Id' => $relay->id,
                'X-Stellar-Relay-Timestamp' => $timestamp,
                'X-Stellar-Relay-Signature' => 'v1='.$signature,
            ];

            if ($context['commerce_order_id'] !== null) {
                $headers['X-Stellar-Commerce-Order-Id'] = $context['commerce_order_id'];
            }
            if ($context['commerce_order_item_id'] !== null) {
                $headers['X-Stellar-Commerce-Order-Item-Id'] = $context['commerce_order_item_id'];
            }
            if ($context['commerce_unit'] !== null) {
                $headers['X-Stellar-Commerce-Unit'] = (string) $context['commerce_unit'];
            }

            $response = Http::withoutRedirecting()
                ->connectTimeout((int) config('services.stellar_wholesale.webhook_relay_connect_timeout', 2))
                ->timeout((int) config('services.stellar_wholesale.webhook_relay_timeout', 5))
                ->withHeaders($headers)
                ->withBody($body, $relay->content_type ?: 'application/json')
                ->post((string) config('services.stellar_wholesale.webhook_relay_url'));

            if ($response->successful()) {
                $relay->forceFill([
                    'status' => 'delivered',
                    'response_status' => $response->status(),
                    'last_error' => null,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                ])->save();

                return ['status' => 'delivered', 'http_status' => $response->status()];
            }

            return $this->scheduleRetry($relay, $attempt, 'HTTP '.$response->status(), $response->status());
        } catch (Throwable $exception) {
            Log::warning('Wholesale webhook relay failed after upstream webhook response.', [
                'relay_id' => $relay->id,
                'attempt' => $attempt,
                'exception' => class_basename($exception),
            ]);

            return $this->scheduleRetry($relay, $attempt, class_basename($exception), null);
        }
    }

    public function retryPending(int $limit = 100): array
    {
        $summary = ['processed' => 0, 'delivered' => 0, 'retrying' => 0, 'failed' => 0];

        $relays = WholesaleWebhookRelay::query()
            ->whereIn('status', ['pending', 'retrying'])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('received_at')
            ->limit(max(1, min(1000, $limit)))
            ->get();

        foreach ($relays as $relay) {
            $summary['processed']++;
            $result = $this->relay($relay);
            $status = (string) ($result['status'] ?? 'retrying');

            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    private function configured(): bool
    {
        return trim((string) config('services.stellar_wholesale.webhook_relay_url')) !== ''
            && trim((string) config('services.stellar_wholesale.webhook_relay_secret')) !== '';
    }

    private function scheduleRetry(WholesaleWebhookRelay $relay, int $attempt, string $error, ?int $status): array
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            $relay->forceFill([
                'status' => 'failed',
                'response_status' => $status,
                'last_error' => mb_substr($error, 0, 1000),
                'next_attempt_at' => null,
            ])->save();

            return ['status' => 'failed'];
        }

        $delays = [60, 120, 300, 600, 900, 1800, 3600, 7200, 14400, 28800, 43200];
        $delay = $delays[min($attempt - 1, count($delays) - 1)];

        $relay->forceFill([
            'status' => 'retrying',
            'response_status' => $status,
            'last_error' => mb_substr($error, 0, 1000),
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        return ['status' => 'retrying'];
    }

    /**
     * Read-only correlation. No customer webhook state is changed here.
     * Every upstream request is still relayed even when no Simcard matches.
     *
     * @return array{commerce_order_id:?string,commerce_order_item_id:?string,commerce_unit:?int}
     */
    private function resolveCommerceContext(string $rawBody): array
    {
        $empty = [
            'commerce_order_id' => null,
            'commerce_order_item_id' => null,
            'commerce_unit' => null,
        ];

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $empty;
        }

        $content = $payload['content'] ?? [];
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($content)) {
            return $empty;
        }

        $simcard = null;
        $orderNo = trim((string) ($content['orderNo'] ?? ''));
        $iccid = trim((string) ($content['iccid'] ?? ''));

        if ($orderNo !== '') {
            try {
                $simcard = Simcard::query()
                    ->where('provider', self::PROVIDER)
                    ->where('external_order_id_hash', $this->crypto->deriveExternalOrderHash($orderNo))
                    ->first();
            } catch (Throwable) {
                $simcard = null;
            }
        }

        if ($simcard === null && $iccid !== '') {
            try {
                $simcard = Simcard::query()
                    ->where('provider', self::PROVIDER)
                    ->where('iccid_hash', $this->crypto->deriveIccidHash($iccid))
                    ->first();
            } catch (Throwable) {
                $simcard = null;
            }
        }

        if ($simcard === null) {
            return $empty;
        }

        return [
            'commerce_order_id' => $this->nullableString($simcard->commerce_order_id),
            'commerce_order_item_id' => $this->nullableString($simcard->commerce_order_item_id),
            'commerce_unit' => $simcard->commerce_unit !== null ? max(1, (int) $simcard->commerce_unit) : null,
        ];
    }

    private function signature(string $timestamp, string $relayId, array $context, string $body): string
    {
        $canonical = implode("\n", [
            $timestamp,
            $relayId,
            (string) ($context['commerce_order_id'] ?? ''),
            (string) ($context['commerce_order_item_id'] ?? ''),
            (string) ($context['commerce_unit'] ?? ''),
            $body,
        ]);

        return hash_hmac('sha256', $canonical, (string) config('services.stellar_wholesale.webhook_relay_secret'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
