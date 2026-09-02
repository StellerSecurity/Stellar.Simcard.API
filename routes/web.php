<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    app()->terminating(function (): void {
        // Existing customer Auto Top-Up runs first. For quota-capped virtual plans,
        // a successful paid top-up extends the customer entitlement before quota
        // evaluation, so we must never suspend at the old threshold first.
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
        } catch (Throwable $exception) {
            Log::error('eSIM Auto Top-Up HTTP trigger failed.', [
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);
        }

        // Refresh usage for quota-capped virtual plans only after Auto Top-Up had
        // first chance to extend entitlement. The lock keeps health/Always-On traffic
        // from multiplying provider reads. Global QUEUE_CONNECTION remains untouched.
        try {
            $shouldProcessVirtualQuotas = Cache::add(
                'stellar:esim:virtual-quota:http-trigger',
                now()->timestamp,
                now()->addMinutes(5),
            );

            if ($shouldProcessVirtualQuotas) {
                $exitCode = Artisan::call('esim:process-virtual-quota-caps', [
                    '--limit' => 100,
                ]);

                Log::info('Virtual eSIM quota processor executed from HTTP trigger.', [
                    'exit_code' => $exitCode,
                    'output' => trim(Artisan::output()),
                ]);

                $durationExitCode = Artisan::call('esim:process-virtual-duration-caps', [
                    '--limit' => 500,
                ]);

                Log::info('Virtual eSIM duration processor executed from HTTP trigger.', [
                    'exit_code' => $durationExitCode,
                    'output' => trim(Artisan::output()),
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Virtual eSIM quota HTTP trigger failed.', [
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);
        }

        // Process only the dedicated virtual eSIM queues after the public response.
        // Included virtual TOPUPs and quota suspension never switch the application's
        // normal/default queue away from sync.
        $virtualQueueLockKey = 'stellar:esim:virtual-jobs:http-worker';
        $virtualQueueLockAcquired = false;

        try {
            $virtualQueueLockAcquired = Cache::add(
                $virtualQueueLockKey,
                now()->timestamp,
                now()->addSeconds(90),
            );

            if ($virtualQueueLockAcquired) {
                $connection = (string) config('esim.virtual_fulfillment.connection', 'database');
                $topupQueue = (string) config('esim.virtual_fulfillment.queue', 'virtual-esim-topups');
                $quotaQueue = (string) config('esim.virtual_fulfillment.quota_queue', 'virtual-esim-quota');
                $queues = implode(',', array_values(array_unique(array_filter([$topupQueue, $quotaQueue]))));

                $exitCode = Artisan::call('queue:work', [
                    'connection' => $connection,
                    '--queue' => $queues,
                    '--stop-when-empty' => true,
                    '--max-jobs' => 20,
                    '--max-time' => 60,
                    '--sleep' => 0,
                    '--tries' => 10,
                    '--timeout' => 75,
                ]);

                Log::info('Virtual eSIM queue worker executed from HTTP trigger.', [
                    'connection' => $connection,
                    'queues' => $queues,
                    'exit_code' => $exitCode,
                    'output' => trim(Artisan::output()),
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Virtual eSIM HTTP queue worker failed.', [
                'exception' => basename(str_replace('\\', '/', get_class($exception))),
            ]);
        } finally {
            if ($virtualQueueLockAcquired) {
                Cache::forget($virtualQueueLockKey);
            }
        }
    });

    return response('stellarsimcardapiprod', 200)
        ->header('Content-Type', 'text/plain');
});
