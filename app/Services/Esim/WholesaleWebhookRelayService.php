<?php

namespace App\Services\Esim;

use App\Models\Simcard;
use App\Models\WholesaleWebhookRelay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
     * Capture is intentionally isolated from the existing provider webhook
     * business logic. It is called only after Laravel has produced the original
     * webhook response.
     *
     * Only eSIMs created through Stellar Wholesale are copied into this outbox.
     * Normal Stellar Data/customer eSIM webhook traffic is not stored or relayed.
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

        $context = $this->resolveCommerceContext($rawBody);

        if (!$this->isWholesaleContext($context)) {
            return null;
        }

        return WholesaleWebhookRelay::create([
            'id' => (string) Str::uuid(),
            'provider' => self::PROVIDER,
            'payload_encrypted' => Crypt::encryptString($rawBody),
            'content_type' => mb_substr((string) $request->header('Content-Type', 'application/json'), 0, 120),
            'commerce_order_id' => $context['commerce_order_id'],
            'commerce_order_item_id' => $context['commerce_order_item_id'],
            'commerce_unit' => $context['commerce_unit'],
            'status' => 'pending',
            'attempts' => 0,
            'received_at' => now(),
            'next_attempt_at' => now(),
        ]);
    }

    public function relay(WholesaleWebhookRelay|string $relay): array
    {
        $relayId = $relay instanceof WholesaleWebhookRelay ? (string) $relay->id : (string) $relay;
        $claim = $this->claimForDelivery($relayId);

        if (($claim['status'] ?? null) !== 'claimed') {
            return $claim;
        }

        /** @var WholesaleWebhookRelay $claimedRelay */
        $claimedRelay = $claim['relay'];
        $attempt = (int) $claim['attempt'];

        try {
            $body = Crypt::decryptString((string) $claimedRelay->payload_encrypted);
            $context = $this->contextFromRelay($claimedRelay);

            // Backward compatibility for outbox rows created before commerce
            // context was persisted. Wholesale rows are recovered; historical
            // normal-customer rows are retired without making a network call.
            if (!$this->isWholesaleContext($context)) {
                $context = $this->resolveCommerceContext($body);

                if (!$this->isWholesaleContext($context)) {
                    $claimedRelay->forceFill([
                        'status' => 'ignored',
                        'last_error' => null,
                        'next_attempt_at' => null,
                    ])->save();

                    return ['status' => 'ignored'];
                }

                $claimedRelay->forceFill([
                    'commerce_order_id' => $context['commerce_order_id'],
                    'commerce_order_item_id' => $context['commerce_order_item_id'],
                    'commerce_unit' => $context['commerce_unit'],
                ])->save();
            }

            $timestamp = (string) now()->timestamp;
            $signature = $this->signature($timestamp, $claimedRelay->id, $context, $body);
            $headers = [
                'User-Agent' => 'Stellar-Simcard-Webhook-Relay/1.0',
                'Accept' => 'application/json',
                'Content-Type' => $claimedRelay->content_type ?: 'application/json',
                'X-Stellar-Relay-Id' => $claimedRelay->id,
                'X-Stellar-Relay-Timestamp' => $timestamp,
                'X-Stellar-Relay-Signature' => 'v1='.$signature,
                'X-Stellar-Commerce-Order-Id' => (string) $context['commerce_order_id'],
                'X-Stellar-Commerce-Order-Item-Id' => (string) $context['commerce_order_item_id'],
                'X-Stellar-Commerce-Unit' => (string) $context['commerce_unit'],
            ];

            $response = Http::withoutRedirecting()
                ->connectTimeout((int) config('services.stellar_wholesale.webhook_relay_connect_timeout', 2))
                ->timeout((int) config('services.stellar_wholesale.webhook_relay_timeout', 5))
                ->withHeaders($headers)
                ->withBody($body, $claimedRelay->content_type ?: 'application/json')
                ->post((string) config('services.stellar_wholesale.webhook_relay_url'));

            if ($response->successful()) {
                $claimedRelay->forceFill([
                    'status' => 'delivered',
                    'response_status' => $response->status(),
                    'last_error' => null,
                    'delivered_at' => now(),
                    'next_attempt_at' => null,
                ])->save();

                return ['status' => 'delivered', 'http_status' => $response->status()];
            }

            return $this->scheduleRetry($claimedRelay, $attempt, 'HTTP '.$response->status(), $response->status());
        } catch (Throwable $exception) {
            Log::warning('Wholesale webhook relay failed after upstream webhook response.', [
                'relay_id' => $claimedRelay->id,
                'attempt' => $attempt,
                'exception' => class_basename($exception),
            ]);

            return $this->scheduleRetry($claimedRelay, $attempt, class_basename($exception), null);
        }
    }

    public function retryPending(int $limit = 100): array
    {
        $summary = [
            'processed' => 0,
            'delivered' => 0,
            'retrying' => 0,
            'failed' => 0,
            'ignored' => 0,
            'in_progress' => 0,
        ];
        $staleBefore = now()->subSeconds($this->staleSeconds());

        $relays = WholesaleWebhookRelay::query()
            ->where(function ($query) use ($staleBefore): void {
                $query->where(function ($due): void {
                    $due->whereIn('status', ['pending', 'retrying'])
                        ->where(function ($schedule): void {
                            $schedule->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', now());
                        });
                })->orWhere(function ($stale) use ($staleBefore): void {
                    $stale->where('status', 'delivering')
                        ->where(function ($attempt) use ($staleBefore): void {
                            $attempt->whereNull('last_attempt_at')
                                ->orWhere('last_attempt_at', '<=', $staleBefore);
                        });
                });
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

    private function claimForDelivery(string $relayId): array
    {
        return DB::transaction(function () use ($relayId): array {
            /** @var WholesaleWebhookRelay|null $relay */
            $relay = WholesaleWebhookRelay::query()->lockForUpdate()->find($relayId);

            if ($relay === null) {
                return ['status' => 'missing'];
            }

            if ($relay->status === 'delivered') {
                return ['status' => 'delivered'];
            }

            if (in_array($relay->status, ['failed', 'ignored'], true)) {
                return ['status' => $relay->status];
            }

            if (!$this->configured()) {
                return ['status' => 'disabled'];
            }

            $now = now();
            $staleBefore = $now->copy()->subSeconds($this->staleSeconds());

            if (
                $relay->status === 'delivering'
                && $relay->last_attempt_at !== null
                && $relay->last_attempt_at->gt($staleBefore)
            ) {
                return ['status' => 'in_progress'];
            }

            if (
                in_array($relay->status, ['pending', 'retrying'], true)
                && $relay->next_attempt_at !== null
                && $relay->next_attempt_at->isFuture()
            ) {
                return ['status' => 'not_due'];
            }

            if ($relay->attempts >= self::MAX_ATTEMPTS) {
                $relay->forceFill([
                    'status' => 'failed',
                    'next_attempt_at' => null,
                ])->save();

                return ['status' => 'failed'];
            }

            $attempt = (int) $relay->attempts + 1;
            $relay->forceFill([
                'status' => 'delivering',
                'attempts' => $attempt,
                'last_attempt_at' => $now,
                'next_attempt_at' => null,
            ])->save();

            return [
                'status' => 'claimed',
                'relay' => $relay,
                'attempt' => $attempt,
            ];
        }, 3);
    }

    private function configured(): bool
    {
        return trim((string) config('services.stellar_wholesale.webhook_relay_url')) !== ''
            && trim((string) config('services.stellar_wholesale.webhook_relay_secret')) !== '';
    }

    private function staleSeconds(): int
    {
        return max(30, (int) config('services.stellar_wholesale.webhook_relay_stale_seconds', 120));
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

    /**
     * @return array{commerce_order_id:?string,commerce_order_item_id:?string,commerce_unit:?int}
     */
    private function contextFromRelay(WholesaleWebhookRelay $relay): array
    {
        return [
            'commerce_order_id' => $this->nullableString($relay->commerce_order_id),
            'commerce_order_item_id' => $this->nullableString($relay->commerce_order_item_id),
            'commerce_unit' => $relay->commerce_unit !== null ? max(1, (int) $relay->commerce_unit) : null,
        ];
    }

    private function isWholesaleContext(array $context): bool
    {
        return $this->nullableString($context['commerce_order_id'] ?? null) !== null
            && $this->nullableString($context['commerce_order_item_id'] ?? null) !== null
            && isset($context['commerce_unit'])
            && is_numeric($context['commerce_unit'])
            && (int) $context['commerce_unit'] > 0;
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
