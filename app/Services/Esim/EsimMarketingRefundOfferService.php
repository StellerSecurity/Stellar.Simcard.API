<?php

namespace App\Services\Esim;

use App\Models\Simcard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use StellarSecurity\Notifications\DTO\NotificationEvent;
use StellarSecurity\Notifications\Facades\Notification;
use Throwable;

class EsimMarketingRefundOfferService
{
    public function __construct(
        private readonly EsimCryptoService $crypto,
    ) {}

    /**
     * Records first usage and queues the notification event exactly once.
     * The five-day delay is applied by the notification_rules row.
     *
     * @return array{status: string}
     */
    public function handleUsageDetected(Simcard $simcard): array
    {
        if (!(bool) config('esim-marketing.refund_offer.enabled', true)) {
            return ['status' => 'disabled'];
        }

        $retryAfterMinutes = max(
            1,
            (int) config('esim-marketing.refund_offer.retry_after_minutes', 15)
        );

        $claim = DB::transaction(function () use ($simcard, $retryAfterMinutes): array {
            $locked = Simcard::query()
                ->whereKey($simcard->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return ['status' => 'missing_simcard'];
            }

            $detectedAt = now();

            if ($locked->first_used_at === null) {
                $locked->first_used_at = $detectedAt;
            }

            if ($locked->activated_at === null) {
                $locked->activated_at = $locked->first_used_at;
            }

            if ($locked->state !== 'active') {
                $locked->state = 'active';
            }

            if ($locked->marketing_refund_notification_queued_at !== null) {
                $locked->save();

                return ['status' => 'already_queued'];
            }

            if (empty($locked->email_enc)) {
                $locked->save();

                return ['status' => 'missing_email'];
            }

            if (
                $locked->marketing_refund_notification_attempted_at !== null
                && $locked->marketing_refund_notification_attempted_at->isAfter(
                    now()->subMinutes($retryAfterMinutes)
                )
            ) {
                $locked->save();

                return ['status' => 'retry_later'];
            }

            $locked->marketing_refund_notification_attempted_at = now();
            $locked->save();

            return [
                'status' => 'claimed',
                'simcard' => $locked,
            ];
        });

        if (($claim['status'] ?? null) !== 'claimed') {
            return ['status' => (string) ($claim['status'] ?? 'skipped')];
        }

        /** @var Simcard $claimedSimcard */
        $claimedSimcard = $claim['simcard'];
        $email = $this->resolveEmail($claimedSimcard);

        if ($email === null) {
            return ['status' => 'invalid_email'];
        }

        $eventName = (string) config(
            'esim-marketing.refund_offer.event',
            'esim_first_usage_detected'
        );
        $product = (string) config(
            'esim-marketing.refund_offer.product',
            'stellar-esim-marketing-reward'
        );
        $idempotencyKey = 'esim_marketing_refund_offer_' . (string) $claimedSimcard->id;

        try {
            Notification::send(
                NotificationEvent::make($eventName)
                    ->product($product)
                    ->email($email)
                    ->payload([
                        'refund_amount' => (float) config(
                            'esim-marketing.refund_offer.refund_amount',
                            10
                        ),
                        'discount_percentage' => (int) config(
                            'esim-marketing.refund_offer.discount_percentage',
                            20
                        ),
                        'support_email' => (string) config(
                            'esim-marketing.refund_offer.support_email',
                            'info@stellarsecurity.com'
                        ),
                    ])
                    ->idempotencyKey($idempotencyKey)
            );

            Simcard::query()
                ->whereKey($claimedSimcard->getKey())
                ->whereNull('marketing_refund_notification_queued_at')
                ->update([
                    'marketing_refund_notification_queued_at' => now(),
                ]);

            Log::info('Queued Stellar eSIM marketing refund offer.', [
                'simcard_id' => (string) $claimedSimcard->id,
                'event' => $eventName,
                'idempotency_key' => $idempotencyKey,
            ]);

            return ['status' => 'queued'];
        } catch (Throwable $exception) {
            Log::warning('Failed to queue Stellar eSIM marketing refund offer.', [
                'simcard_id' => (string) $claimedSimcard->id,
                'event' => $eventName,
                'exception' => $exception::class,
            ]);

            return ['status' => 'failed'];
        }
    }

    /**
     * Retries first-usage events that were recorded but not successfully queued.
     *
     * @return array{processed: int, queued: int, skipped: int, failed: int}
     */
    public function retryPending(?int $limit = null): array
    {
        $limit = max(
            1,
            min(
                $limit ?? (int) config('esim-marketing.refund_offer.batch_size', 100),
                1000
            )
        );
        $retryAfterMinutes = max(
            1,
            (int) config('esim-marketing.refund_offer.retry_after_minutes', 15)
        );
        $cutoff = now()->subMinutes($retryAfterMinutes);

        $simcards = Simcard::query()
            ->whereNotNull('first_used_at')
            ->whereNull('marketing_refund_notification_queued_at')
            ->whereNotNull('email_enc')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('marketing_refund_notification_attempted_at')
                    ->orWhere('marketing_refund_notification_attempted_at', '<=', $cutoff);
            })
            ->orderBy('first_used_at')
            ->limit($limit)
            ->get();

        $summary = [
            'processed' => 0,
            'queued' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($simcards as $simcard) {
            $summary['processed']++;
            $result = $this->handleUsageDetected($simcard);
            $status = $result['status'] ?? 'skipped';

            if ($status === 'queued' || $status === 'already_queued') {
                $summary['queued']++;
            } elseif ($status === 'failed' || $status === 'invalid_email') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    private function resolveEmail(Simcard $simcard): ?string
    {
        try {
            $email = $this->crypto->decryptEmail((string) $simcard->email_enc);
        } catch (Throwable $exception) {
            Log::warning('Could not decrypt eSIM email for marketing refund offer.', [
                'simcard_id' => (string) $simcard->id,
                'exception' => $exception::class,
            ]);

            return null;
        }

        $email = $this->crypto->normalizeEmail($email);

        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email
            : null;
    }
}
