<?php

use Illuminate\Support\Facades\Http;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;

beforeEach(function () {
    config()->set('laravel-multipay.polar.access_token', 'polar_oat_test');
    config()->set('laravel-multipay.polar.server', 'sandbox');
    config()->set('laravel-multipay.polar.base_url', 'https://sandbox-api.polar.sh');
});

it('creates a plan and maps the package interval to the Polar recurring interval', function (string $interval, array $expectedRecurring) {
    Http::fake([
        'sandbox-api.polar.sh/v1/products/' => Http::response(['id' => 'prod_plan']),
    ]);

    $planId = (new Polar())->createPaymentPlan('Pro', '750', $interval, 'Pro plan', 'USD');

    expect($planId)->toBe('prod_plan');

    Http::assertSent(function ($request) use ($expectedRecurring) {
        return str_ends_with($request->url(), '/products/')
            && $request['recurring_interval'] === $expectedRecurring['interval']
            && $request['recurring_interval_count'] === $expectedRecurring['count']
            && $request['prices'][0]['amount_type'] === 'fixed'
            && $request['prices'][0]['price_amount'] === 75000
            && $request['prices'][0]['price_currency'] === 'usd'
            && !array_key_exists('organization_id', $request->data());
    });
})->with([
    'daily' => ['daily', ['interval' => 'day', 'count' => 1]],
    'weekly' => ['weekly', ['interval' => 'week', 'count' => 1]],
    'monthly' => ['monthly', ['interval' => 'month', 'count' => 1]],
    'quarterly' => ['quarterly', ['interval' => 'month', 'count' => 3]],
    'biannually' => ['biannually', ['interval' => 'month', 'count' => 6]],
    'annually' => ['annually', ['interval' => 'year', 'count' => 1]],
    'yearly' => ['yearly', ['interval' => 'year', 'count' => 1]],
]);

it('rejects an hourly billing interval', function () {
    Http::fake();

    (new Polar())->createPaymentPlan('Pro', '750', 'hourly', 'Pro plan', 'USD');
})->throws(Exception::class, 'does not support hourly');
