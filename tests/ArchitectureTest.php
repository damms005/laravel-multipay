<?php

use Illuminate\Support\Str;
use Damms005\LaravelMultipay\Contracts\ManagesSubscriptions;
use Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity;

describe('architecture tests', function () {
    arch('dev debug calls')
        ->expect('Damms005\LaravelMultipay')
        ->not->toUse(['die', 'dd', 'dump']);

    arch('php preset')->preset()->php();
    arch('laravel preset')->preset()->laravel()->ignoring('Damms005\LaravelMultipay\LaravelMultipayServiceProvider');
    arch('security preset')->preset()->security()->ignoring('md5');

})
    ->skip(
        Str::startsWith(Illuminate\Foundation\Application::VERSION, '10.'),
        'Skipped on Laravel 10'
    );

it('requires every SupportsSubscriptionQuantity implementor to also implement ManagesSubscriptions', function () {
    $handlerFiles = glob(__DIR__ . '/../src/Services/PaymentHandlers/*.php') ?: [];

    $handlerClasses = collect($handlerFiles)
        ->map(fn (string $path): string => 'Damms005\\LaravelMultipay\\Services\\PaymentHandlers\\' . basename($path, '.php'))
        ->filter(fn (string $fqcn): bool => class_exists($fqcn));

    $implementors = $handlerClasses
        ->filter(fn (string $fqcn): bool => is_subclass_of($fqcn, SupportsSubscriptionQuantity::class));

    expect($implementors)->not->toBeEmpty();

    $offenders = $implementors
        ->reject(fn (string $fqcn): bool => is_subclass_of($fqcn, ManagesSubscriptions::class));

    expect($offenders->all())->toBe([]);
});
