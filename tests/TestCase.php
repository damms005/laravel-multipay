<?php

namespace Damms005\LaravelMultipay\Tests;

use Damms005\LaravelMultipay\LaravelMultipayServiceProvider;
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
            fn (string $modelName) => 'Damms005\\LaravelMultipay\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->withoutExceptionHandling();
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelMultipayServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
    }
}
