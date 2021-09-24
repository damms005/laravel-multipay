<?php

namespace Damms005\LaravelCashier;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Damms005\LaravelCashier\Commands\LaravelCashierCommand;

class LaravelCashierServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-cashier')
            ->hasConfigFile('laravel-cashier')
            ->hasViews()
            ->hasMigration('create_laravel-cashier_table')
            ->hasCommand(LaravelCashierCommand::class);
    }
}
