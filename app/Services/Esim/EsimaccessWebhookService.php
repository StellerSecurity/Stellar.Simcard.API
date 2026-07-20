<?php

namespace App\Services\Esim;

use App\Models\EsimWebhookEvent;
use App\Models\Simcard;
use App\Services\SimcardActionLinkService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use StellarSecurity\Notifications\DTO\NotificationEvent;
use StellarSecurity\Notifications\Facades\Notification;
use Throwable;

class EsimaccessWebhookService
{
    private const PROVIDER = 'esimaccess';

    private const SUPPORTED_NOTIFY_TYPES = [
        'ORDER_STATUS',
        'ESIM_STATUS',
        'DATA_USAGE',
        'VALIDITY_USAGE',
        'CHECK_HEALTH',
        'SMDP_EVENT',
    ];

    private const REDACTED = '[REDACTED]';

    public function __construct(
        private readonly EsimCryptoService $crypto,
        private readonly EsimProvider $provider,
        private readonly SimcardActionLinkService $actionLinks,
    ) {}

    public function handle(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);
        $content = $normalized['content'];
        $notifyType = $normalized['notifyType'];

        $externalOrderIdHash = $this->externalOrderIdHash($content);
        $iccidHash = $this->iccidHash($content);
        $transactionIdHash = $this->transactionIdHash($content);
        $idempotencyKey = $this->idempotencyKey($notifyType, $content);

        if ($notifyType === 'CHECK_HEALTH') {
            return $this->handleHealthCheck($normalized, $idempotencyKey);
        }

        $result = DB::transaction(function () use (
            $normalized,
            $content,
            $notifyType,
            $externalOrderIdHash,
            $iccidHash,
            $transactionIdHash,
            $idempotencyKey
        ) {
            $event = EsimWebhookEvent::where('idempotency_key', $idempotencyKey)->first();

            if ($event !== null) {
                return [
                    'status' => 'duplicate',
                    'notify_type' => $notifyType,
                ];
            }

            $event = EsimWebhookEvent::create([
                'id' => (string) Str::uuid(),
                'provider' => self::PROVIDER,
                'notify_type' => $notifyType,
                'idempotency_key' => $idempotencyKey,
                'external_order_id_hash' => $externalOrderIdHash,
                'transaction_id_hash' => $transactionIdHash,
                'transaction_id_last4' => $this->crypto->last4($this->nullableString($content['transactionId'] ?? null)),
                'iccid_hash' => $iccidHash,
                'iccid_last4' => $this->crypto->last4($this->nullableString($content['iccid'] ?? null)),
                'status' => 'received',
                'payload_redacted' => $this->redactPayload($normalized),
                'received_at' => now(),
            ]);

            try {
                $simcard = $this->findSimcard($externalOrderIdHash, $iccidHash);

                if ($simcard === null) {
                    $event->status = 'ignored';
                    $event->error_code = 'simcard_not_found';
                    $event->error_message = 'No matching simcard found for webhook identifiers.';
                    $event->processed_at = now();
                    $event->save();

                    return [
                        'status' => 'ignored',
                        'notify_type' => $notifyType,
                        'reason' => 'simcard_not_found',
                    ];
                }

                $event->simcard_id = $simcard->id;
                $applied = $this->applyToSimcard($simcard, $notifyType, $content);

                if (!$applied) {
                    $event->status = 'ignored';
                    $event->error_code = 'notify_type_not_mutated';
                    $event->error_message = 'Webhook type intentionally did not mutate simcard state, type: ' . $notifyType;
                    $event->processed_at = now();
                    $event->save();

                    return [
                        'status' => 'ignored',
                        'notify_type' => $notifyType,
                        'simcard_id' => $simcard->id,
                        'reason' => 'notify_type_not_mutated',
                    ];
                }

                $event->status = 'processed';
                $event->processed_at = now();
                $event->save();

                return [
                    'status' => 'processed',
                    'notify_type' => $notifyType,
                    'simcard_id' => $simcard->id,
                    'webhook_event_id' => $event->id,
                ];
            } catch (Throwable $exception) {
                $event->status = 'failed';
                $event->error_code = 'processing_exception';
                $event->error_message = $this->safeExceptionName($exception);
                $event->processed_at = now();
                $event->save();

                Log::warning('Failed to process eSIM Access webhook.', [
                    'notify_type' => $notifyType,
                    'event_id' => $event->id,
                    'exception' => $this->safeExceptionName($exception),
                ]);

                return [
                    'status' => 'failed',
                    'notify_type' => $notifyType,
                ];
            }
        });

        if (($result['status'] ?? null) === 'processed') {
            $sms = $this->sendWebhookSmsIfNeeded($notifyType, $content, $result);

            if ($sms !== null) {
                $result['sms'] = $sms;
            }

            $email = $this->sendWebhookEmailIfNeeded($notifyType, $content, $result);

            if ($email !== null) {
                $result['email'] = $email;
            }
        }

        return $result;
    }

    private function handleHealthCheck(array $normalized, string $idempotencyKey): array
    {
        DB::transaction(function () use ($normalized, $idempotencyKey) {
            $event = EsimWebhookEvent::where('idempotency_key', $idempotencyKey)->first();

            if ($event !== null) {
                return;
            }

            EsimWebhookEvent::create([
                'id' => (string) Str::uuid(),
                'provider' => self::PROVIDER,
                'notify_type' => 'CHECK_HEALTH',
                'idempotency_key' => $idempotencyKey,
                'status' => 'processed',
                'payload_redacted' => $this->redactPayload($normalized),
                'received_at' => now(),
                'processed_at' => now(),
            ]);
        });

        return [
            'status' => 'processed',
            'notify_type' => 'CHECK_HEALTH',
            'health' => 'ok',
        ];
    }

    private function normalizePayload(array $payload): array
    {
        $notifyType = $this->nullableString($payload['notifyType'] ?? $payload['notify_type'] ?? null);

        if ($notifyType === null) {
            throw new RuntimeException('Missing notifyType.');
        }

        $notifyType = strtoupper($notifyType);

        if (!in_array($notifyType, self::SUPPORTED_NOTIFY_TYPES, true)) {
            throw new RuntimeException('Unsupported notifyType.');
        }

        $content = $payload['content'] ?? [];

        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($content)) {
            $content = [];
        }

        return [
            'notifyType' => $notifyType,
            'content' => $content,
        ];
    }

    private function externalOrderIdHash(array $content): ?string
    {
        $orderNo = $this->nullableString($content['orderNo'] ?? null);

        return $orderNo === null ? null : $this->crypto->deriveExternalOrderHash($orderNo);
    }

    private function iccidHash(array $content): ?string
    {
        $iccid = $this->nullableString($content['iccid'] ?? null);

        return $iccid === null ? null : $this->crypto->deriveIccidHash($iccid);
    }

    private function transactionIdHash(array $content): ?string
    {
        $transactionId = $this->nullableString($content['transactionId'] ?? null);

        return $transactionId === null ? null : $this->crypto->deriveTransactionHash($transactionId);
    }

    private function findSimcard(?string $externalOrderIdHash, ?string $iccidHash): ?Simcard
    {
        if ($externalOrderIdHash !== null) {
            $simcard = Simcard::where('provider', self::PROVIDER)
                ->where('external_order_id_hash', $externalOrderIdHash)
                ->first();

            if ($simcard !== null) {
                return $simcard;
            }
        }

        if ($iccidHash !== null) {
            return Simcard::where('provider', self::PROVIDER)
                ->where('iccid_hash', $iccidHash)
                ->first();
        }

        return null;
    }

    private function applyToSimcard(Simcard $simcard, string $notifyType, array $content): bool
    {
        // ORDER_STATUS is intentionally not mutated here. Existing order-ready flow remains separate.
        // SMDP_EVENT is accepted defensively but does not currently drive business logic.
        if (in_array($notifyType, ['ORDER_STATUS', 'SMDP_EVENT'], true)) {
            return false;
        }

        $simcard->last_webhook_at = now();
        $this->applySensitiveIdentifiers($simcard, $content);

        match ($notifyType) {
            'ESIM_STATUS' => $this->applyEsimStatus($simcard, $content),
            'DATA_USAGE' => $this->applyDataUsage($simcard, $content),
            'VALIDITY_USAGE' => $this->applyValidityUsage($simcard, $content),
            default => null,
        };

        $simcard->save();

        return true;
    }

    private function applySensitiveIdentifiers(Simcard $simcard, array $content): void
    {
        $iccid = $this->nullableString($content['iccid'] ?? null);

        if ($iccid === null) {
            return;
        }

        $simcard->iccid_enc = $this->crypto->encryptSensitiveValue($iccid);
        $simcard->iccid_hash = $this->crypto->deriveIccidHash($iccid);
        $simcard->iccid_last4 = $this->crypto->last4($iccid);
    }

    private function applyEsimStatus(Simcard $simcard, array $content): void
    {
        $esimStatus = $this->nullableString($content['esimStatus'] ?? null);
        $smdpStatus = $this->nullableString($content['smdpStatus'] ?? null);

        if ($esimStatus !== null) {
            $simcard->esim_status = $esimStatus;
        }

        if ($smdpStatus !== null) {
            $simcard->smdp_status = $smdpStatus;
        }

        if ($esimStatus === 'IN_USE') {
            $simcard->state = 'active';
            $simcard->activated_at = $simcard->activated_at ?? now();
        }
    }

    private function applyDataUsage(Simcard $simcard, array $content): void
    {
        $simcard->data_status = 'low';
        $simcard->total_volume = $this->nullableInt($content['totalVolume'] ?? null);
        $simcard->order_usage = $this->nullableInt($content['orderUsage'] ?? null);
        $simcard->remaining_volume = $this->nullableInt($content['remain'] ?? null);
    }

    private function applyValidityUsage(Simcard $simcard, array $content): void
    {
        $simcard->validity_status = 'expiring';
        $simcard->remaining_validity = $this->nullableInt($content['remain'] ?? null);

        $expiredTime = $this->nullableString($content['expiredTime'] ?? null);
        if ($expiredTime !== null) {
            $simcard->expires_at = Carbon::parse($expiredTime);
        }
    }

    private function sendWebhookSmsIfNeeded(string $notifyType, array $content, array $result): ?array
    {
        $iccid = $this->nullableString($content['iccid'] ?? null);

        if ($iccid === null) {
            return [
                'status' => 'skipped',
                'reason' => 'missing_iccid',
            ];
        }

        $simcardId = $this->nullableString($result['simcard_id'] ?? null);
        $simcard = $simcardId === null ? null : Simcard::find($simcardId);

        if ($simcard === null) {
            return [
                'status' => 'skipped',
                'reason' => 'missing_simcard',
            ];
        }

        $message = $this->smsMessageForWebhook($notifyType, $content, $simcard, $this->nullableString($result['webhook_event_id'] ?? null));

        if ($message === null) {
            return null;
        }

        try {
            $this->provider->sendSms($iccid, $message, $this->preferredProviderAccount($simcard));

            return [
                'status' => 'sent',
            ];
        } catch (Throwable $exception) {
            Log::warning('Failed to send eSIM Access webhook SMS.', [
                'notify_type' => $notifyType,
                'simcard_id' => $simcard->id,
                'exception' => $this->safeExceptionName($exception),
            ]);

            return [
                'status' => 'failed',
                'reason' => 'provider_sms_failed',
            ];
        }
    }

    private function smsMessageForWebhook(string $notifyType, array $content, Simcard $simcard, ?string $webhookEventId): ?string
    {
        if ($notifyType === 'ESIM_STATUS' && ($content['esimStatus'] ?? null) === 'IN_USE') {
            return 'Stellar eSIM is now active. Stellar VPN is included for free. Use the login from your order confirmation. Get Stellar VPN here: https://stellarvpn.org/download';
        }

        if ($notifyType === 'DATA_USAGE') {
            $url = $this->actionLinks->createTopupUrl($simcard, 'data_low', $webhookEventId);
            $planPhrase = $this->safePlanPhraseForSms($content);

            return 'Your Stellar eSIM' . $planPhrase . ' is almost out of data. Top up here: ' . $url;
        }

        if ($notifyType === 'VALIDITY_USAGE') {
            $url = $this->actionLinks->createTopupUrl($simcard, 'validity_expiring', $webhookEventId);
            $planPhrase = $this->safePlanPhraseForSms($content);

            return 'Your Stellar eSIM' . $planPhrase . ' expires soon. Extend or buy another plan here: ' . $url;
        }

        return null;
    }


    private function sendWebhookEmailIfNeeded(string $notifyType, array $content, array $result): ?array
    {
        $simcardId = $this->nullableString($result['simcard_id'] ?? null);
        $simcard = $simcardId === null ? null : Simcard::find($simcardId);

        if ($simcard === null) {
            return [
                'status' => 'skipped',
                'reason' => 'missing_simcard',
            ];
        }

        $email = $this->resolveSimcardEmail($simcard);

        if ($email === null) {
            return [
                'status' => 'skipped',
                'reason' => 'missing_email',
            ];
        }

        $webhookEventId = $this->nullableString($result['webhook_event_id'] ?? null);
        $emailPayload = $this->emailPayloadForWebhook($notifyType, $content, $simcard, $webhookEventId);

        if ($emailPayload === null) {
            return null;
        }

        $event = $emailPayload['event'];
        $payload = $emailPayload['payload'];
        $idempotencyKey = 'esim_webhook_email_' . ($webhookEventId ?: hash('sha256', json_encode([$notifyType, $content]))) . '_' . $event;

        try {
            Notification::send(
                NotificationEvent::make($event)
                    ->product('stellar-data')
                    ->email($email)
                    ->payload($payload)
                    ->idempotencyKey($idempotencyKey)
            );

            Log::info('eSIM webhook email sent.', [
                'notify_type' => $notifyType,
                'simcard_id' => (string) $simcard->id,
                'event' => $event,
                'has_email' => true,
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'status' => 'sent',
                'event' => $event,
            ];
        } catch (Throwable $exception) {
            Log::warning('Failed to send eSIM webhook email.', [
                'notify_type' => $notifyType,
                'simcard_id' => (string) $simcard->id,
                'event' => $event,
                'exception' => $this->safeExceptionName($exception),
            ]);

            return [
                'status' => 'failed',
                'reason' => 'notification_email_failed',
                'event' => $event,
            ];
        }
    }

    /**
     * @return array{event: string, payload: array}|null
     */
    private function emailPayloadForWebhook(string $notifyType, array $content, Simcard $simcard, ?string $webhookEventId): ?array
    {
        $packageLabel = $this->resolveSafePackageLabelForSms($content, $simcard);
        $basePayload = array_filter([
            'app_name' => 'Stellar Data',
            'simcard_id' => (string) $simcard->id,
            'package_label' => $packageLabel,
            'manage_url' => 'https://data.stellarsecurity.com/',
            'support_url' => 'https://stellarsecurity.com/contact-us',
            'support_email' => 'info@stellarsecurity.com',
        ], static fn ($value) => $value !== null && $value !== '');

        if ($notifyType === 'DATA_USAGE') {
            $topupUrl = $this->actionLinks->createTopupUrl($simcard, 'data_low', $webhookEventId);

            return [
                'event' => 'esim_low_data',
                'payload' => array_merge($basePayload, array_filter([
                    'headline' => 'Your eSIM data is running low',
                    'intro' => 'Your remaining mobile data is low. Add a top-up to stay connected.',
                    'topup_url' => $topupUrl,
                    'manage_url' => $topupUrl,
                    'remaining_bytes' => $this->nullableInt($content['remain'] ?? null),
                    'total_bytes' => $this->nullableInt($content['totalVolume'] ?? null),
                    'used_bytes' => $this->nullableInt($content['orderUsage'] ?? null),
                ], static fn ($value) => $value !== null && $value !== '')),
            ];
        }

        if ($notifyType === 'VALIDITY_USAGE') {
            $topupUrl = $this->actionLinks->createTopupUrl($simcard, 'validity_expiring', $webhookEventId);

            return [
                'event' => 'esim_expiring_soon',
                'payload' => array_merge($basePayload, array_filter([
                    'headline' => 'Your eSIM is expiring soon',
                    'intro' => 'Your eSIM is close to expiry. Extend it or buy another plan to stay connected.',
                    'topup_url' => $topupUrl,
                    'manage_url' => $topupUrl,
                    'remaining_validity' => $this->nullableInt($content['remain'] ?? null),
                    'expires_at' => $this->nullableString($content['expiredTime'] ?? null),
                ], static fn ($value) => $value !== null && $value !== '')),
            ];
        }

        return null;
    }

    private function resolveSimcardEmail(Simcard $simcard): ?string
    {
        if (empty($simcard->email_enc)) {
            return null;
        }

        try {
            $email = $this->crypto->decryptEmail((string) $simcard->email_enc);
        } catch (Throwable $exception) {
            Log::warning('Could not decrypt simcard email for webhook notification.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => $this->safeExceptionName($exception),
            ]);

            return null;
        }

        $email = $this->crypto->normalizeEmail($email);

        if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    private function safePlanPhraseForSms(array $content): string
    {
        $label = $this->resolveSafePackageLabelForSms($content, $simcard);

        return $label === null ? '' : ' for ' . $label;
    }

    private function resolveSafePackageLabelForSms(array $content, Simcard $simcard): ?string
    {
        $orderNo = $this->nullableString($content['orderNo'] ?? null);
        $iccid = $this->nullableString($content['iccid'] ?? null);

        if ($orderNo === null && $iccid === null) {
            return null;
        }

        try {
            $response = $this->provider->queryEsim($orderNo, $iccid, $this->preferredProviderAccount($simcard));
            $candidate = $this->extractPackageLabelFromProviderResponse($response);

            return $this->sanitizePackageLabelForSms($candidate);
        } catch (Throwable $exception) {
            Log::info('Could not resolve safe eSIM package label for webhook SMS.', [
                'exception' => $this->safeExceptionName($exception),
            ]);

            return null;
        }
    }

    private function preferredProviderAccount(Simcard $simcard): string
    {
        return in_array($simcard->provider_account, ['primary', 'legacy'], true)
            ? $simcard->provider_account
            : 'legacy';
    }

    private function extractPackageLabelFromProviderResponse(array $response): ?string
    {
        $esim = Arr::get($response, 'obj.esimList.0');

        if (!is_array($esim)) {
            return null;
        }

        $package = Arr::get($esim, 'packageList.0');

        if (!is_array($package)) {
            return null;
        }

        foreach (['packageName', 'name', 'slug', 'locationCode'] as $key) {
            $value = $this->nullableString($package[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function sanitizePackageLabelForSms(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = preg_replace('/[^\p{L}\p{N} .,+\-\/()]/u', '', $label) ?? '';
        $label = preg_replace('/\s+/u', ' ', $label) ?? '';
        $label = trim($label);

        if ($label === '') {
            return null;
        }

        return Str::limit($label, 60, '');
    }

    private function idempotencyKey(string $notifyType, array $content): string
    {
        $parts = [
            self::PROVIDER,
            $notifyType,
            Arr::get($content, 'orderNo'),
            Arr::get($content, 'transactionId'),
            Arr::get($content, 'iccid'),
            Arr::get($content, 'orderStatus'),
            Arr::get($content, 'esimStatus'),
            Arr::get($content, 'smdpStatus'),
            Arr::get($content, 'remain'),
            Arr::get($content, 'expiredTime'),
        ];

        return hash_hmac('sha256', implode('|', array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : json_encode($value),
            $parts
        )), (string) config('esim.crypto.hash_key'));
    }

    private function redactPayload(array $payload): array
    {
        return $this->redactArray($payload);
    }

    private function redactArray(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if ($this->isSensitivePayloadKey((string) $key)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redactArray($item) : $item;
        }

        return $redacted;
    }

    private function isSensitivePayloadKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        return in_array($normalized, [
            'iccid',
            'orderno',
            'transactionid',
            'esimtranno',
            'esimtransactionno',
            'tranno',
            'transactionno',
            'timestamp',
            'eventtimestamp',
            'seqnumber',
            'sequencenumber',
            'imsi',
            'eid',
            'msisdn',
            'phone',
            'phonenumber',
            'email',
            'accesscode',
            'secretkey',
            'signature',
            'token',
            'authorization',
        ], true);
    }

    private function safeExceptionName(Throwable $exception): string
    {
        return substr(basename(str_replace('\\', '/', get_class($exception))), 0, 255);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
