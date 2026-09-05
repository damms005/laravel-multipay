<?php

use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Illuminate\Http\Request;

function paystackWebhookRequest(array $payload, string $secretKey = 'sk_12345', ?string $signatureOverride = null, bool $withSignature = true): Request
{
    $body = json_encode($payload);

    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($withSignature) {
        $headers['HTTP_X_PAYSTACK_SIGNATURE'] = $signatureOverride ?? hash_hmac('sha512', $body, $secretKey);
    }

    return Request::create('/payment/completed/notify', 'POST', [], [], [], $headers, $body);
}

it('rejects a paystack webhook whose signature does not match', function () {
    $request = paystackWebhookRequest([
        'event' => 'subscription.create',
        'data' => ['subscription_code' => 'SUB_forged'],
    ], signatureOverride: 'clearly-wrong-signature');

    expect(fn () => (new Paystack())->handleExternalWebhookRequest($request))
        ->toThrow(Exception::class, 'Paystack webhook signature verification failed.');
});

it('rejects a paystack webhook signed with a different secret key', function () {
    $request = paystackWebhookRequest([
        'event' => 'subscription.create',
        'data' => ['subscription_code' => 'SUB_other_account'],
    ], secretKey: 'sk_a_different_integration');

    expect(fn () => (new Paystack())->handleExternalWebhookRequest($request))
        ->toThrow(Exception::class, 'Paystack webhook signature verification failed.');
});

it('passes an unsigned webhook to the next handler rather than claiming it', function () {
    $request = paystackWebhookRequest([
        'event' => 'subscription.create',
        'data' => ['subscription_code' => 'SUB_unsigned'],
    ], withSignature: false);

    expect(fn () => (new Paystack())->handleExternalWebhookRequest($request))
        ->toThrow(UnknownWebhookException::class);
});

it('refuses to verify a paystack webhook when no secret key is configured', function () {
    config()->set('laravel-multipay.paystack_secret_key', '');

    $request = paystackWebhookRequest([
        'event' => 'subscription.create',
        'data' => ['subscription_code' => 'SUB_unconfigured'],
    ]);

    expect(fn () => (new Paystack())->handleExternalWebhookRequest($request))
        ->toThrow(Exception::class, 'Paystack secret key is not configured. Set PAYSTACK_SECRET_KEY.');
});

it('handles a correctly signed webhook through to its event handler', function () {
    $plan = PaymentPlan::create([
        'name' => 'signature-plan',
        'amount' => '5000',
        'interval' => 'monthly',
        'description' => 'desc',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_signature',
    ]);

    $subscription = Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
    ]);

    $request = paystackWebhookRequest([
        'event' => 'subscription.create',
        'data' => [
            'subscription_code' => 'SUB_signed',
            'email_token' => 'tok_signed',
            'plan' => ['plan_code' => 'PLN_signature'],
            'customer' => ['customer_code' => 'CUS_x', 'email' => 'a@b.com'],
        ],
    ]);

    (new Paystack())->handleExternalWebhookRequest($request);

    $subscription->refresh();

    expect($subscription->payment_handler_subscription_code)->toBe('SUB_signed')
        ->and($subscription->payment_handler_email_token)->toBe('tok_signed');
});
