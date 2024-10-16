<?php

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Damms005\LaravelMultipay\Tests\TestCase;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;

uses(TestCase::class)
    ->beforeEach(function () {
        config()->set('app.key', Str::random(32));
        config()->set('app.debug', true);
        config()->set('app.debug', true);
        config()->set('laravel-multipay.extended_layout', 'laravel-multipay::test.layout');
        config()->set('laravel-multipay.user_model_fqcn', User::class);
        config()->set('laravel-multipay.paystack_secret_key', 'sk_12345');

        doAuth();
    })
    ->in(__DIR__);


function doAuth()
{
    DB::statement('CREATE TABLE users ( id, email )');

    DB::table('users')->insert(['id' => 1, 'email' => 'user@gmail.com']);

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

function createPayment(): Payment
{
    return Payment::create([
        "user_id" => 1,
        "completion_url" => 'https://www.localhost.com',
        "transaction_reference" => Str::uuid(),
        "payment_processor_name" => Str::uuid(),
        "transaction_currency" => 'USD',
        "transaction_description" => Str::random(),
        "original_amount_displayed_to_user" => 500,
        "metadata" => [],
    ]);
}
