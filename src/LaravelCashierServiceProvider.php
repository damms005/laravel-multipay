<?php

namespace Damms005\LaravelCashier;

use Damms005\LaravelCashier\Models\Payment;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelCashier\Services\PaymentService;
use Illuminate\Support\ServiceProvider;

class LaravelCashierServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../views', 'laravel-cashier');

        $this->publishes([__DIR__ . '/../config/laravel-cashier.php' => config_path('laravel-cashier.php')], 'laravel-cashier-config');

        $this->bootFlutterwave();

        $this->app->bind('laravel-cashier', function ($app) {
            return $app->make(BasePaymentHandler::class);
        });

        $this->app->bind('handler-for-payment', function ($app, $args) {
            /** @var Payment */
            $payment = $args[0];

            throw_if(get_class($payment) !== Payment::class, 'System error: only Payment can be resolved by this binding');

            return $payment->getPaymentProvider();
        });

        $this->app->bind(BasePaymentHandler::class, function ($app) {
            return new BasePaymentHandler();
        });

        $this->app->bind(PaymentService::class, function ($app) {
            return new PaymentService();
        });
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-cashier.php',
            'laravel-cashier'
        );
    }

    public function bootFlutterwave()
    {
        config(['flutterwave.publicKey' => env('FLW_PUBLIC_KEY')]);
        config(['flutterwave.secretKey' => env('FLW_SECRET_KEY')]);
        config(['flutterwave.secretHash' => env('FLW_SECRET_HASH')]);
    }
}
