<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    try {
        $shouldProcessAutoTopups = Cache::add(
            'stellar:esim:auto-topups:http-trigger',
            now()->timestamp,
            300
        );

        if ($shouldProcessAutoTopups) {
            app()->terminating(function () {
                try {
                    $exitCode = Artisan::call('esim:process-auto-topups', [
                        '--limit' => 100,
                    ]);

                    Log::info('eSIM Auto Top-Up processor executed from HTTP trigger.', [
                        'exit_code' => $exitCode,
                        'output' => trim(Artisan::output()),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('eSIM Auto Top-Up HTTP trigger failed.', [
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);
                }
            });
        }
    } catch (\Throwable $e) {
        Log::warning('Could not schedule eSIM Auto Top-Up processor from HTTP trigger.', [
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ]);
    }

    return response('stellarsimcardapiprod', 200)
        ->header('Content-Type', 'text/plain');
});
