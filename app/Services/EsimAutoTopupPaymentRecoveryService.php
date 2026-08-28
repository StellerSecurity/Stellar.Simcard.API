<?php

namespace App\Services;

use App\Models\Simcard;
use App\Models\SimcardAutoTopup;
use App\Models\SimcardAutoTopupAttempt;
use App\Models\SimcardTopupSession;
use App\Services\Esim\EsimCryptoService;
use App\Services\Esim\EsimProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use StellarSecurity\Notifications\DTO\NotificationEvent;
use StellarSecurity\Notifications\Facades\Notification;
use Throwable;

class EsimAutoTopupPaymentRecoveryService
{
    private const ATTEMPT_FAILED = 'FAILED';

    private const RETRY_AFTER_MINUTES = 5;

    public function __construct(
        private readonly EsimCryptoService $crypto,
        private readonly EsimProvider $provider,
    ) {}

    /** @return array{email:string,sms:string} */
    public function notify(string $attemptId, bool $force = false): array
    {
        return [
            'email' => $this->sendEmail($attemptId, $force),
            'sms' => $this->sendSms($attemptId, $force),
        ];
    }

    /** @return array{processed:int,sent:int,skipped:int,failed:int} */
    public function retryPendingEmails(int $limit = 100): array
    {
        return $this->retryPendingChannel(
            attemptedColumn: 'payment_failure_notification_attempted_at',
            sentColumn: 'payment_failure_notification_sent_at',
            sender: fn (string $attemptId): string => $this->sendEmail($attemptId),
            limit: $limit,
        );
    }

    /** @return array{processed:int,sent:int,skipped:int,failed:int} */
    public function retryPendingSms(int $limit = 100): array
    {
        return $this->retryPendingChannel(
            attemptedColumn: 'payment_failure_sms_attempted_at',
            sentColumn: 'payment_failure_sms_sent_at',
            sender: fn (string $attemptId): string => $this->sendSms($attemptId),
            limit: $limit,
        );
    }

    public function sendEmail(string $attemptId, bool $force = false): string
    {
        if (! $this->claimChannel($attemptId, 'email', $force)) {
            return 'skipped';
        }

        try {
            [$attempt, $config, $simcard] = $this->context($attemptId);
            $email = $this->resolveEmail($simcard);
            if ($email === null) {
                throw new RuntimeException('Customer email is unavailable.');
            }

            $recoveryUrl = $this->paymentRecoveryUrl($attempt, $config);
            $idempotencyKey = 'esim_auto_topup_payment_failed_'.(string) $attempt->id;
            $topupSession = SimcardTopupSession::query()->find($attempt->topup_session_id);

            Notification::send(
                NotificationEvent::make('esim_auto_topup_payment_failed')
                    ->product('stellar-data')
                    ->email($email)
                    ->payload(array_filter([
                        'simcard_id' => (string) $simcard->id,
                        'topup_session_id' => (string) $attempt->topup_session_id,
                        'auto_topup_cycle' => (int) $attempt->cycle,
                        'package_name' => trim((string) ($topupSession?->package_name ?? $topupSession?->package_code ?? '')),
                        'iccid_last4' => trim((string) ($simcard->iccid_last4 ?? '')),
                        'update_payment_method_url' => $recoveryUrl,
                        'manage_url' => 'https://data.stellarsecurity.com/',
                        'support_url' => 'https://stellarsecurity.com/contact-us',
                    ], static fn ($value) => $value !== null && $value !== ''))
                    ->idempotencyKey($idempotencyKey)
            );

            $this->markChannelSent($attemptId, 'email');
            Log::info('eSIM Auto Top-Up payment recovery email sent.', [
                'simcard_id' => (string) $simcard->id,
                'auto_topup_attempt_id' => (string) $attempt->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return 'sent';
        } catch (Throwable $exception) {
            $this->markChannelFailed($attemptId, 'email', $exception);

            return 'failed';
        }
    }

    public function sendSms(string $attemptId, bool $force = false): string
    {
        if (! $this->claimChannel($attemptId, 'sms', $force)) {
            return 'skipped';
        }

        try {
            [$attempt, $config, $simcard] = $this->context($attemptId);
            if (empty($simcard->iccid_enc)) {
                throw new RuntimeException('eSIM ICCID is unavailable.');
            }

            $iccid = trim($this->crypto->decryptSensitiveValue((string) $simcard->iccid_enc));
            if ($iccid === '') {
                throw new RuntimeException('eSIM ICCID is unavailable.');
            }

            $recoveryUrl = $this->paymentRecoveryUrl($attempt, $config);
            $account = $this->provider->resolveAccountForEsim(
                null,
                $iccid,
                in_array((string) $simcard->provider_account, ['primary', 'legacy'], true)
                    ? (string) $simcard->provider_account
                    : 'legacy',
            );

            $this->provider->sendSms(
                $iccid,
                "Your Stellar eSIM Auto Top-Up could not be completed. Update your payment card securely: {$recoveryUrl}",
                $account,
            );

            $this->markChannelSent($attemptId, 'sms');
            Log::info('eSIM Auto Top-Up payment recovery SMS sent.', [
                'simcard_id' => (string) $simcard->id,
                'auto_topup_attempt_id' => (string) $attempt->id,
                'provider_account' => $account,
            ]);

            return 'sent';
        } catch (Throwable $exception) {
            $this->markChannelFailed($attemptId, 'sms', $exception);

            return 'failed';
        }
    }

    private function claimChannel(string $attemptId, string $channel, bool $force): bool
    {
        [$attemptedColumn, $sentColumn, $failureColumn] = $this->channelColumns($channel);

        return DB::transaction(function () use ($attemptId, $force, $attemptedColumn, $sentColumn, $failureColumn): bool {
            $attempt = SimcardAutoTopupAttempt::query()->where('id', $attemptId)->lockForUpdate()->first();
            if (
                $attempt === null
                || $attempt->status !== self::ATTEMPT_FAILED
                || $attempt->payment_failed_at === null
                || $attempt->payment_recovered_at !== null
                || $attempt->{$sentColumn} !== null
            ) {
                return false;
            }

            if (! $force && $attempt->{$attemptedColumn} !== null && $attempt->{$attemptedColumn}->gt(now()->subMinutes(self::RETRY_AFTER_MINUTES))) {
                return false;
            }

            $attempt->{$attemptedColumn} = now();
            $attempt->{$failureColumn} = null;
            $attempt->save();

            return true;
        });
    }

    /** @return array{0:SimcardAutoTopupAttempt,1:SimcardAutoTopup,2:Simcard} */
    private function context(string $attemptId): array
    {
        $attempt = SimcardAutoTopupAttempt::query()->where('id', $attemptId)->first();
        $config = $attempt !== null
            ? SimcardAutoTopup::query()->where('id', $attempt->auto_topup_id)->first()
            : null;
        $simcard = $config !== null
            ? Simcard::query()->where('id', $config->simcard_id)->first()
            : null;

        if (
            $attempt === null
            || $config === null
            || $simcard === null
            || $attempt->status !== self::ATTEMPT_FAILED
            || $attempt->payment_recovered_at !== null
        ) {
            throw new RuntimeException('Auto Top-Up payment recovery context is incomplete.');
        }

        return [$attempt, $config, $simcard];
    }

    private function paymentRecoveryUrl(SimcardAutoTopupAttempt $attempt, SimcardAutoTopup $config): string
    {
        if (
            ! empty($attempt->payment_recovery_url_enc)
            && $attempt->payment_recovery_expires_at !== null
            && $attempt->payment_recovery_expires_at->gt(now()->addMinutes(5))
        ) {
            return $this->crypto->decryptSensitiveValue((string) $attempt->payment_recovery_url_enc);
        }

        $endpoint = trim((string) config('services.stellar_commerce.auto_topup_payment_recovery_url', ''));
        $username = (string) config('services.stellar_commerce.username', '');
        $password = (string) config('services.stellar_commerce.password', '');
        if ($endpoint === '' || $username === '' || $password === '') {
            throw new RuntimeException('Commerce payment recovery is not configured.');
        }

        $response = Http::asJson()
            ->withBasicAuth($username, $password)
            ->connectTimeout(10)
            ->timeout(45)
            ->post($endpoint, [
                'parent_order_id' => (string) $config->parent_commerce_order_id,
                'parent_order_item_id' => (string) $config->parent_commerce_order_item_id,
                'simcard_id' => (string) $config->simcard_id,
                'commerce_unit' => (int) $config->commerce_unit,
                'topup_session_id' => (string) $attempt->topup_session_id,
                'attempt_key' => (string) $attempt->attempt_key,
                'idempotency_key' => 'esim_auto_topup_recovery_'.(string) $attempt->id,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Commerce could not create a payment recovery link.');
        }

        $body = $response->json();
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        $url = trim((string) ($data['update_payment_method_url'] ?? $data['url'] ?? ''));
        if (! $this->isSecureUrl($url)) {
            throw new RuntimeException('Commerce returned an invalid payment recovery link.');
        }

        try {
            $expiresAt = ! empty($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : now()->addMinutes(30);
        } catch (Throwable) {
            throw new RuntimeException('Commerce returned an invalid payment recovery expiry.');
        }

        if ($expiresAt->lte(now()->addMinute())) {
            throw new RuntimeException('Commerce returned an expired payment recovery link.');
        }

        $stored = SimcardAutoTopupAttempt::query()
            ->where('id', $attempt->id)
            ->where('status', self::ATTEMPT_FAILED)
            ->whereNull('payment_recovered_at')
            ->update([
                'payment_recovery_url_enc' => $this->crypto->encryptSensitiveValue($url),
                'payment_recovery_expires_at' => $expiresAt,
            ]);

        if ($stored !== 1) {
            throw new RuntimeException('Auto Top-Up payment recovery is already complete.');
        }

        return $url;
    }

    private function isSecureUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 4096 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && trim((string) parse_url($url, PHP_URL_HOST)) !== '';
    }

    private function resolveEmail(Simcard $simcard): ?string
    {
        if (empty($simcard->email_enc)) {
            return null;
        }

        $email = $this->crypto->decryptEmail((string) $simcard->email_enc);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private function markChannelSent(string $attemptId, string $channel): void
    {
        [, $sentColumn, $failureColumn] = $this->channelColumns($channel);
        SimcardAutoTopupAttempt::query()->where('id', $attemptId)->whereNull($sentColumn)->update([
            $sentColumn => now(),
            $failureColumn => null,
        ]);
    }

    private function markChannelFailed(string $attemptId, string $channel, Throwable $exception): void
    {
        [, $sentColumn, $failureColumn] = $this->channelColumns($channel);
        $exceptionName = basename(str_replace('\\', '/', get_class($exception)));
        SimcardAutoTopupAttempt::query()->where('id', $attemptId)->whereNull($sentColumn)->update([
            $failureColumn => mb_substr('Payment recovery delivery failed: '.$exceptionName, 0, 2000),
        ]);

        Log::warning("Failed to send eSIM Auto Top-Up payment recovery {$channel}.", [
            'auto_topup_attempt_id' => $attemptId,
            'exception' => $exceptionName,
        ]);
    }

    /** @return array{0:string,1:string,2:string} */
    private function channelColumns(string $channel): array
    {
        return $channel === 'sms'
            ? ['payment_failure_sms_attempted_at', 'payment_failure_sms_sent_at', 'payment_failure_sms_failure_reason']
            : ['payment_failure_notification_attempted_at', 'payment_failure_notification_sent_at', 'payment_failure_notification_failure_reason'];
    }

    /** @param callable(string):string $sender
     * @return array{processed:int,sent:int,skipped:int,failed:int}
     */
    private function retryPendingChannel(string $attemptedColumn, string $sentColumn, callable $sender, int $limit): array
    {
        $summary = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $attempts = SimcardAutoTopupAttempt::query()
            ->where('status', self::ATTEMPT_FAILED)
            ->whereNotNull('payment_failed_at')
            ->whereNull('payment_recovered_at')
            ->whereNull($sentColumn)
            ->where(function ($query) use ($attemptedColumn): void {
                $query->whereNull($attemptedColumn)
                    ->orWhere($attemptedColumn, '<=', now()->subMinutes(self::RETRY_AFTER_MINUTES));
            })
            ->orderBy('updated_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($attempts as $attempt) {
            $summary['processed']++;
            $result = $sender((string) $attempt->id);
            $summary[array_key_exists($result, $summary) ? $result : 'skipped']++;
        }

        return $summary;
    }
}
