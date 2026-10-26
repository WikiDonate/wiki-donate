<?php

namespace App\Providers;

use App\Models\PaymentMethod;
use App\Observers\PaymentMethodObserver;
use App\Services\Payment\PaymentCredentials;
use Illuminate\Support\ServiceProvider;
use L5Swagger\L5SwaggerServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(L5SwaggerServiceProvider::class);

        // One registry read (and one decryption) per request, no matter how
        // many providers/clients ask for credentials.
        $this->app->singleton(PaymentCredentials::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registry changes (enable/disable, mode, currencies) are financial
        // configuration: audit who changed what. Credentials are never logged.
        PaymentMethod::observe(PaymentMethodObserver::class);
    }
}
