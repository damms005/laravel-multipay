<?php

namespace Damms005\LaravelCashier;

use Illuminate\Support\ServiceProvider;

class LaravelCashierServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../views', 'laravel-cashier');

        $this->publishes([__DIR__ . '/../config/laravel-cashier.php' => config_path('laravel-cashier.php')], 'laravel-cashier-config');
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/laravel-cashier.php',
            'laravel-cashier'
        );
    }
}
