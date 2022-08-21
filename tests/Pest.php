<?php

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Damms005\LaravelMultipay\Tests\TestCase;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;
use Illuminate\Foundation\Auth\User;

uses(TestCase::class)
    ->beforeEach(function () {
        config()->set('app.key', Str::random(32));
        config()->set('app.debug', true);
        config()->set('app.debug', true);
        config()->set('laravel-multipay.default_payment_handler_fqcn', Remita::class);
        config()->set('laravel-multipay.extended_layout', 'laravel-multipay::test.layout');
        config()->set('laravel-multipay.user_model_fqcn', User::class);

        doAuth();
    })
    ->in(__DIR__);


function doAuth()
{
    DB::statement('CREATE TABLE users ( id )');

    DB::table('users')->insert(['id' => 1]);

    Auth::loginUsingId(1);
}

function getSampleInitialPaymentRequest()
{
    return [
        'currency' => 'NGN',
        'amount' => 500,
        'user_id' => 1,
        'transaction_description' => 'foo-bar',
        'payment_processor' => Remita::getUniquePaymentHandlerName(),
    ];
}
