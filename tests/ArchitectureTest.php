<?php

use Illuminate\Support\Str;

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
