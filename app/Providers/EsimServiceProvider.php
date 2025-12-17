<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Esim\EsimProvider;
use App\Services\Esim\EsimaccessProvider;

class EsimServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EsimProvider::class, function () {
            return new EsimaccessProvider(
                config('esim.esimaccess.base_url'),
                config('esim.esimaccess.access_code'),
                config('esim.esimaccess.secret_key'),
            );
        });
    }
}
