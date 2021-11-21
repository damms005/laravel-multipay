<?php

namespace Damms005\LaravelCashier;

use Damms005\LaravelCashier\Models\Payment;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LaravelCashierServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadPaymentPolicy();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../views', 'laravel-cashier');

        $this->publishes([__DIR__ . '/../config/laravel-cashier.php' => config_path('laravel-cashier.php')], 'laravel-cashier-config');

        $this->bootFlutterwave();

        $this->app->bind('laravel-cashier', function ($app) {
            return new BasePaymentHandler();
        });
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-cashier.php',
            'laravel-cashier'
        );
    }

    public function loadPaymentPolicy()
    {
        if (empty(config('laravel-cashier.policy_class_fqcn'))) {
            return;
        }

        Gate::guessPolicyNamesUsing(function ($modelClass) {
            if ($modelClass == Payment::class) {
                return config('laravel-cashier.policy_class_fqcn');
            }
        });
    }

    public function bootFlutterwave()
    {
        config(['flutterwave.publicKey' => env('FLW_PUBLIC_KEY')]);
        config(['flutterwave.secretKey' => env('FLW_SECRET_KEY')]);
        config(['flutterwave.secretHash' => env('FLW_SECRET_HASH')]);
    }
}
