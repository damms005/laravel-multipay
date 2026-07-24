<?php

use Illuminate\Support\Facades\Http;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Bachs;

beforeEach(function () {
    config()->set('laravel-multipay.bachs.secret_key', 'sk_sandbox_test');
    config()->set('laravel-multipay.bachs.base_url', 'https://sandbox-api.bachs.io');
});

it('creates a plan and maps the package interval to the Bachs billing cycle', function (string $interval, array $expectedCycle) {
    Http::fake([
        'sandbox-api.bachs.io/v1/products' => Http::response(['id' => 'prod_plan']),
    ]);

    $planId = (new Bachs())->createPaymentPlan('Pro', '75000', $interval, 'Pro plan', 'USD');

    expect($planId)->toBe('prod_plan');

    Http::assertSent(function ($request) use ($expectedCycle) {
        return str_ends_with($request->url(), '/products')
            && $request['price']['price_type'] === 'fixed'
            && $request['price']['amount'] === '75000.00'
            && $request['price']['currency'] === 'USD'
            && $request['billing_cycle'] === $expectedCycle;
    });
})->with([
    'daily' => ['daily', ['interval' => 'day', 'frequency' => 1]],
    'weekly' => ['weekly', ['interval' => 'week', 'frequency' => 1]],
    'monthly' => ['monthly', ['interval' => 'month', 'frequency' => 1]],
    'quarterly' => ['quarterly', ['interval' => 'month', 'frequency' => 3]],
    'biannually' => ['biannually', ['interval' => 'month', 'frequency' => 6]],
    'annually' => ['annually', ['interval' => 'year', 'frequency' => 1]],
    'yearly' => ['yearly', ['interval' => 'year', 'frequency' => 1]],
]);

it('refuses to create a non-USD subscription plan', function () {
    Http::fake();

    (new Bachs())->createPaymentPlan('Pro', '75000', 'monthly', 'Pro plan', 'NGN');
})->throws(Exception::class, 'USD-card only');

it('rejects an hourly billing interval', function () {
    Http::fake();

    (new Bachs())->createPaymentPlan('Pro', '75000', 'hourly', 'Pro plan', 'USD');
})->throws(Exception::class, 'does not support hourly');
