<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Auth\User;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;

beforeEach(function () {
    config()->set('laravel-multipay.polar.access_token', 'polar_oat_test');
    config()->set('laravel-multipay.polar.server', 'sandbox');
    config()->set('laravel-multipay.polar.base_url', 'https://sandbox-api.polar.sh');
});

function polarPlan(): PaymentPlan
{
    return PaymentPlan::create([
        'name' => 'pro-usd',
        'amount' => '750',
        'interval' => 'monthly',
        'description' => 'Pro plan',
        'currency' => 'USD',
        'payment_handler_fqcn' => Polar::class,
        'payment_handler_plan_id' => 'prod_plan',
    ]);
}

it('opens a checkout for a plan and stores the checkout id on the payment', function () {
    $plan = polarPlan();

    $payment = createPayment();
    $payment->update(['transaction_reference' => 'SUB-REF-1', 'payment_processor_name' => 'polar']);

    Http::fake([
        'sandbox-api.polar.sh/v1/checkouts/' => Http::response([
            'id' => 'checkout-sub-1',
            'url' => 'https://sandbox.polar.sh/checkout/checkout-sub-1',
        ]),
    ]);

    $user = new User();
    $user->email = 'subscriber@example.com';

    $url = (new Polar())->subscribeToPlan($user, $plan, 'SUB-REF-1');

    expect($url)->toBe('https://sandbox.polar.sh/checkout/checkout-sub-1');

    expect(Payment::where('transaction_reference', 'SUB-REF-1')->first()->processor_transaction_reference)
        ->toBe('checkout-sub-1');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/checkouts/')
            && $request['products'] === ['prod_plan']
            && $request['customer_email'] === 'subscriber@example.com'
            && $request['metadata']['transaction_reference'] === 'SUB-REF-1'
            && !array_key_exists('organization_id', $request->data());
    });
});

it('cancels a subscription via PATCH with cancel_at_period_end true', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/subscriptions/sub_123' => Http::response(['status' => 'active', 'cancel_at_period_end' => true]),
    ]);

    (new Polar())->disableSubscription('sub_123', 'ignored-token');

    Http::assertSent(function ($request) {
        return $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/subscriptions/sub_123')
            && $request['cancel_at_period_end'] === true;
    });
});

it('resumes a subscription via PATCH with cancel_at_period_end false', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/subscriptions/sub_123' => Http::response(['status' => 'active', 'cancel_at_period_end' => false]),
    ]);

    (new Polar())->enableSubscription('sub_123', 'ignored-token');

    Http::assertSent(function ($request) {
        return $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/subscriptions/sub_123')
            && $request['cancel_at_period_end'] === false;
    });
});

it('throws when Polar rejects the cancellation', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/subscriptions/sub_123' => Http::response(['detail' => 'ResourceNotFound'], 404),
    ]);

    (new Polar())->disableSubscription('sub_123', 'ignored-token');
})->throws(Exception::class, 'ResourceNotFound');

it('maps subscription details returned by Polar', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/subscriptions/sub_123' => Http::response([
            'id' => 'sub_123',
            'status' => 'active',
            'current_period_end' => '2026-08-01T00:00:00Z',
        ]),
    ]);

    $details = (new Polar())->getSubscriptionDetails('sub_123');

    expect($details)->toBe([
        'subscription_code' => 'sub_123',
        'email_token' => null,
        'status' => 'active',
        'next_payment_date' => '2026-08-01T00:00:00Z',
    ]);
});
