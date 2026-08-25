<?php

use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\Esim\WholesaleWebhookRelayService;
use App\Services\EsimAutoTopupService;
use App\Services\EsimDataUsageAlertService;
use App\Services\VirtualEsimQuotaService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('esim:dispatch-marketing-refund-offers {--limit=100}', function () {
    $summary = app(EsimMarketingRefundOfferService::class)
        ->retryPending((int) $this->option('limit'));

    $this->info(sprintf(
        'Processed: %d, queued: %d, skipped: %d, failed: %d',
        $summary['processed'],
        $summary['queued'],
        $summary['skipped'],
        $summary['failed'],
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Retry eSIM first-usage marketing refund events that have not been queued');

Schedule::command('esim:dispatch-marketing-refund-offers')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Artisan::command('esim:process-auto-topups {--limit=100} {--simcard=} {--refresh-only : Refresh provider usage without starting or retrying a charge}', function () {
    $summary = app(EsimAutoTopupService::class)
        ->processPending(
            (int) $this->option('limit'),
            $this->option('simcard') !== null ? (string) $this->option('simcard') : null,
            (bool) $this->option('refresh-only'),
        );

    $this->info(sprintf(
        'Processed: %d, triggered: %d, skipped: %d, failed: %d; provider usage attempted: %d, refreshed: %d, skipped: %d, failed: %d; notifications processed: %d, sent: %d, skipped: %d, failed: %d; SMS processed: %d, sent: %d, skipped: %d, failed: %d',
        $summary['processed'],
        $summary['triggered'],
        $summary['skipped'],
        $summary['failed'],
        $summary['usage_refresh_attempted'],
        $summary['usage_refreshed'],
        $summary['usage_refresh_skipped'],
        $summary['usage_refresh_failed'],
        $summary['notifications_processed'],
        $summary['notifications_sent'],
        $summary['notifications_skipped'],
        $summary['notifications_failed'],
        $summary['sms_processed'],
        $summary['sms_sent'],
        $summary['sms_skipped'],
        $summary['sms_failed'],
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Process eligible and retryable eSIM Auto Top-Up cycles');

Artisan::command('esim:send-auto-topup-sms {attempt}', function () {
    $result = app(EsimAutoTopupService::class)
        ->sendSuccessSmsForAttempt((string) $this->argument('attempt'), true);

    $this->info('SMS result: ' . $result);

    return $result === 'failed' ? 1 : 0;
})->purpose('Send or retry the Auto Top-Up success SMS for one fulfilled attempt');

Schedule::command('esim:process-auto-topups')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('esim:process-data-usage-alerts {--limit=100} {--simcard=} {--force : Ignore the normal provider polling interval}', function () {
    $summary = app(EsimDataUsageAlertService::class)
        ->processPending(
            (int) $this->option('limit'),
            $this->option('simcard') !== null ? (string) $this->option('simcard') : null,
            (bool) $this->option('force'),
        );

    $this->info(sprintf(
        'Processed: %d, refreshed: %d, triggered: %d, rearmed: %d, skipped: %d, failed: %d; SMS sent: %d; emails sent: %d',
        $summary['processed'],
        $summary['refreshed'],
        $summary['triggered'],
        $summary['rearmed'],
        $summary['skipped'],
        $summary['failed'],
        $summary['sms_sent'],
        $summary['email_sent'],
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Send 50% data alerts to active eSIMs without Auto Top-Up');

Schedule::command('esim:process-data-usage-alerts')
    ->everyFifteenMinutes()
    ->withoutOverlapping();



Artisan::command('esim:process-virtual-quota-caps {--limit=100} {--simcard=} {--force : Ignore the normal quota polling interval}', function () {
    $summary = app(VirtualEsimQuotaService::class)->processPending(
        (int) $this->option('limit'),
        $this->option('simcard') !== null ? (string) $this->option('simcard') : null,
        (bool) $this->option('force'),
    );

    $this->info(sprintf(
        'Processed: %d, refreshed: %d, monitoring: %d, suspend queued: %d, suspended: %d, skipped: %d, failed: %d',
        $summary['processed'],
        $summary['refreshed'],
        $summary['monitoring'],
        $summary['suspend_queued'],
        $summary['suspended'],
        $summary['skipped'],
        $summary['failed'],
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Refresh usage and enforce Stellar quotas for virtual eSIM fallback plans');

Schedule::command('esim:process-virtual-quota-caps --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Artisan::command('esim:retry-wholesale-webhook-relays {--limit=100}', function () {
    $summary = app(WholesaleWebhookRelayService::class)->retryPending((int) $this->option('limit'));

    $this->info(sprintf(
        'Processed: %d, delivered: %d, retrying: %d, failed: %d, ignored: %d, in progress: %d',
        $summary['processed'],
        $summary['delivered'],
        $summary['retrying'],
        $summary['failed'],
        $summary['ignored'],
        $summary['in_progress'],
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Retry isolated webhook relays to Stellar Wholesale');

Schedule::command('esim:retry-wholesale-webhook-relays --limit=100')
    ->everyMinute()
    ->withoutOverlapping();
