<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    app()->terminating(function (): void {
        // Process only the dedicated virtual eSIM included-top-up queue.
        // This runs after the HTTP response has been sent and does not change
        // the application's global QUEUE_CONNECTION (which may remain "sync").
        $virtualQueueLockKey = 'stellar:esim:virtual-topups:http-worker';
        $virtualQueueLockAcquired = false;

        try {
            $virtualQueueLockAcquired = Cache::add(
                $virtualQueueLockKey,
                now()->timestamp,
                now()->addSeconds(90),
            );

            if ($virtualQueueLockAcquired) {
                $connection = (string) config('esim.virtual_fulfillment.connection', 'database');
                $queue = (string) config('esim.virtual_fulfillment.queue', 'virtual-esim-topups');

                $exitCode = Artisan::call('queue:work', [
                    'connection' => $connection,
                    '--queue' => $queue,
                    '--stop-when-empty' => true,
                    '--max-jobs' => 10,
                    '--max-time' => 60,
                    '--sleep' => 0,
                    '--tries' => 10,
                    '--timeout' => 75,
                ]);

                Log::info('Virtual eSIM included Top-Up queue worker executed from HTTP trigger.', [
                    'connection' => $connection,
                    'queue' => $queue,
                    'exit_code' => $exitCode,
                    'output' => trim(Artisan::output()),
                ]);
            }
        } catch (\Throwable $exception) {
            // The health endpoint response has already been produced. Queue
            // processing must never make the public root endpoint unhealthy.
            Log::error('Virtual eSIM included Top-Up HTTP queue worker failed.', [
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);
        } finally {
            if ($virtualQueueLockAcquired) {
                Cache::forget($virtualQueueLockKey);
            }
        }

        // Keep the existing customer Auto Top-Up HTTP trigger independent from
        // virtual-plan included top-ups. A lock miss here must not affect the
        // virtual eSIM queue worker above (and vice versa).
        try {
            $shouldProcessAutoTopups = Cache::add(
                'stellar:esim:auto-topups:http-trigger',
                now()->timestamp,
                now()->addMinutes(5),
            );

            if ($shouldProcessAutoTopups) {
                $exitCode = Artisan::call('esim:process-auto-topups', [
                    '--limit' => 100,
                ]);

                Log::info('eSIM Auto Top-Up processor executed from HTTP trigger.', [
                    'exit_code' => $exitCode,
                    'output' => trim(Artisan::output()),
                ]);
            }
        } catch (\Throwable $exception) {
            // The health endpoint response has already been produced. Auto Top-Up
            // processing must never make the public root endpoint unhealthy.
            Log::error('eSIM Auto Top-Up HTTP trigger failed.', [
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);
        }
    });

    return response('stellarsimcardapiprod', 200)
        ->header('Content-Type', 'text/plain');
});
