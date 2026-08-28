<?php

namespace App\Providers;

use App\Services\Deployment\AutomaticDatabaseMigrations;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AutomaticDatabaseMigrations::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            $this->app->make(AutomaticDatabaseMigrations::class)->run();
        }

        RateLimiter::for('sim.user.read', function (Request $request): Limit {
            return Limit::perMinute(120)
                ->by($this->serviceCallerKey($request, 'read'));
        });

        RateLimiter::for('sim.user.write', function (Request $request): Limit {
            return Limit::perMinute(60)
                ->by($this->serviceCallerKey($request, 'write'));
        });
    }

    private function serviceCallerKey(Request $request, string $operation): string
    {
        $identity = trim((string) $request->getUser());

        if ($identity === '') {
            $identity = $request->ip() ?: 'unknown';
        }

        return sprintf(
            'sim-user:%s:%s',
            $operation,
            hash('sha256', $identity),
        );
    }
}
