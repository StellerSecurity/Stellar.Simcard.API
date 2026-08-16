<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    app()->terminating(function (): void {
        try {
            $shouldProcessAutoTopups = Cache::add(
                'stellar:esim:auto-topups:http-trigger',
                now()->timestamp,
                now()->addMinutes(5),
            );

            if (! $shouldProcessAutoTopups) {
                return;
            }

            $exitCode = Artisan::call('esim:process-auto-topups', [
                '--limit' => 100,
            ]);

            Log::info('eSIM Auto Top-Up processor executed from HTTP trigger.', [
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ]);
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
