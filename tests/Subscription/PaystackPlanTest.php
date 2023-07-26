<?php

use Illuminate\Support\Facades\Http;
use Damms005\LaravelMultipay\Services\SubscriptionService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;

it('can create plan using Paystack', function () {
    Http::fake();
    Http::preventStrayRequests();

    (new SubscriptionService())
        ->createPaymentPlan(new Paystack(), 'plan', '1000', 'monthly', 'description', 'NGN');

    $this->assertDatabaseHas('payment_plans', [
        'name' => 'plan',
        'amount' => '1000',
        'interval' => 'monthly',
        'description' => 'description',
        'currency' => 'NGN',
    ]);
})->skip();
