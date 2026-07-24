<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Auth\User;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Bachs;

beforeEach(function () {
    config()->set('laravel-multipay.bachs.secret_key', 'sk_sandbox_test');
    config()->set('laravel-multipay.bachs.base_url', 'https://sandbox-api.bachs.io');
});

function bachsPlan(): PaymentPlan
{
    return PaymentPlan::create([
        'name' => 'pro-usd',
        'amount' => '75000',
        'interval' => 'monthly',
        'description' => 'Pro plan',
        'currency' => 'USD',
        'payment_handler_fqcn' => Bachs::class,
        'payment_handler_plan_id' => 'prod_plan',
    ]);
}

it('opens a checkout session for a plan and stores the checkout id on the payment', function () {
    $plan = bachsPlan();

    $payment = createPayment();
    $payment->update(['transaction_reference' => 'SUB-REF-1', 'payment_processor_name' => 'Bachs']);

    Http::fake([
        'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response([
            'checkout_id' => 'checkout-sub-1',
            'checkout_url' => 'https://sandbox.bachs.io/pay/checkout-sub-1',
        ]),
    ]);

    $user = new User();
    $user->email = 'subscriber@example.com';

    $url = (new Bachs())->subscribeToPlan($user, $plan, 'SUB-REF-1');

    expect($url)->toBe('https://sandbox.bachs.io/pay/checkout-sub-1');

    expect(Payment::where('transaction_reference', 'SUB-REF-1')->first()->processor_transaction_reference)
        ->toBe('checkout-sub-1');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/checkout-sessions')
            && $request['product_cart'] === [['product_id' => 'prod_plan', 'quantity' => 1]]
            && $request['customer']['email'] === 'subscriber@example.com'
            && $request['reference'] === 'SUB-REF-1';
    });
});

it('cancels a subscription via DELETE with cancel_at_period_end', function () {
    Http::fake([
        'sandbox-api.bachs.io/v1/subscriptions/sub_123' => Http::response(['status' => 'canceled']),
    ]);

    (new Bachs())->disableSubscription('sub_123', 'ignored-token');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/subscriptions/sub_123')
            && $request['cancel_at_period_end'] === true;
    });
});

it('throws when Bachs rejects the cancellation', function () {
    Http::fake([
        'sandbox-api.bachs.io/v1/subscriptions/sub_123' => Http::response(['detail' => 'Not found', 'error_code' => 'SUBSCRIPTION_NOT_FOUND'], 404),
    ]);

    (new Bachs())->disableSubscription('sub_123', 'ignored-token');
})->throws(Exception::class, 'SUBSCRIPTION_NOT_FOUND');

it('maps subscription details returned by Bachs', function () {
    Http::fake([
        'sandbox-api.bachs.io/v1/subscriptions/sub_123' => Http::response([
            'id' => 'sub_123',
            'status' => 'active',
            'next_billed_at' => '2026-08-01T00:00:00Z',
        ]),
    ]);

    $details = (new Bachs())->getSubscriptionDetails('sub_123');

    expect($details)->toBe([
        'subscription_code' => 'sub_123',
        'email_token' => null,
        'status' => 'active',
        'next_payment_date' => '2026-08-01T00:00:00Z',
    ]);
});

it('does not support resuming a canceled subscription', function () {
    (new Bachs())->enableSubscription('sub_123', 'ignored-token');
})->throws(Exception::class, 'does not support resuming');
