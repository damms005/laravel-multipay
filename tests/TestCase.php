<?php

namespace Damms005\LaravelCashier\Tests;

use Damms005\LaravelCashier\LaravelCashierServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Damms005\\LaravelCashier\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->withoutExceptionHandling();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelCashierServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}
