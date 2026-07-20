<?php

namespace App\Providers;

use App\Services\Esim\EsimaccessProvider;
use App\Services\Esim\EsimProvider;
use Illuminate\Support\ServiceProvider;

class EsimServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EsimProvider::class, function () {
            return EsimaccessProvider::fromConfig();
        });
    }
}
