<?php

use App\Services\Esim\EsimMarketingRefundOfferService;
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
