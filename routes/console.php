<?php

use App\Services\Esim\EsimMarketingRefundOfferService;
use App\Services\EsimAutoTopupService;
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
        'Processed: %d, triggered: %d, skipped: %d, failed: %d; provider usage attempted: %d, refreshed: %d, skipped: %d, failed: %d; notifications processed: %d, sent: %d, skipped: %d, failed: %d',
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
    ));

    return $summary['failed'] > 0 ? 1 : 0;
})->purpose('Process eligible and retryable eSIM Auto Top-Up cycles');

Schedule::command('esim:process-auto-topups')
    ->everyFiveMinutes()
    ->withoutOverlapping();

