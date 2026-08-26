<?php

use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Services\SubscriptionService;
use Illuminate\Foundation\Auth\User;
use Yabacon\Paystack as PaystackHelper;

beforeEach(function () {
    $this->payment = createPayment();
    $this->payment->update(['payment_processor_name' => 'Paystack']);
    $this->payment->refresh();
});

it('offers only the channels Paystack can charge again when the payment must stay chargeable', function () {
    $capturedPayload = captureInitializedPayload();

    $this->payment->update([
        'metadata' => [Payment::METADATA_REQUIRES_REUSABLE_AUTHORIZATION => true],
    ]);

    (new Paystack())->proceedToPaymentGateway($this->payment, 'far-away-land');

    expect($capturedPayload->value['channels'])->toBe(['card', 'bank'])
        ->and($capturedPayload->value['metadata']['custom_filters']['recurring'])->toBeTrue();
});

it('drops requested channels that Paystack cannot charge again', function () {
    $capturedPayload = captureInitializedPayload();

    $this->payment->update([
        'metadata' => [
            'channels' => ['card', 'ussd', 'bank_transfer'],
            Payment::METADATA_REQUIRES_REUSABLE_AUTHORIZATION => true,
        ],
    ]);

    (new Paystack())->proceedToPaymentGateway($this->payment, 'far-away-land');

    expect($capturedPayload->value['channels'])->toBe(['card'])
        ->and($capturedPayload->value)->not->toHaveKey('metadata');
});

it('follows the configured recurring channels', function () {
    config()->set('laravel-multipay.paystack_recurring_channels', ['card']);

    $capturedPayload = captureInitializedPayload();

    $this->payment->update([
        'metadata' => [Payment::METADATA_REQUIRES_REUSABLE_AUTHORIZATION => true],
    ]);

    (new Paystack())->proceedToPaymentGateway($this->payment, 'far-away-land');

    expect(Paystack::recurringChannels())->toBe(['card'])
        ->and($capturedPayload->value['channels'])->toBe(['card']);
});

it('leaves a one-off checkout on every channel', function () {
    $capturedPayload = captureInitializedPayload();

    (new Paystack())->proceedToPaymentGateway($this->payment, 'far-away-land');

    expect($capturedPayload->value)->not->toHaveKey('channels')
        ->and($capturedPayload->value)->not->toHaveKey('metadata');
});

it('forwards additional_payment_payload keys to Paystack', function () {
    $capturedPayload = captureInitializedPayload();

    $this->payment->update([
        'metadata' => ['additional_payment_payload' => ['currency' => 'GHS', 'channels' => ['mobile_money']]],
    ]);

    (new Paystack())->proceedToPaymentGateway($this->payment, 'far-away-land');

    expect($capturedPayload->value['currency'])->toBe('GHS')
        ->and($capturedPayload->value['channels'])->toBe(['mobile_money']);
});

it('offers only the channels Paystack can charge again when subscribing to a plan', function () {
    $capturedPayload = captureInitializedPayload();

    $plan = PaymentPlan::create([
        'name' => 'plan-monthly',
        'amount' => '1000',
        'interval' => 'monthly',
        'description' => 'description',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::class,
        'payment_handler_plan_id' => 'PLN_test123',
    ]);

    $user = new User();
    $user->email = 'subscriber@example.com';

    (new SubscriptionService())->subscribeToPlan(new Paystack(), $user, $plan, 'http://localhost', 'PLAN-REF-001');

    expect($capturedPayload->value['channels'])->toBe(['card', 'bank'])
        ->and($capturedPayload->value['metadata']['custom_filters']['recurring'])->toBeTrue()
        ->and($capturedPayload->value['plan'])->toBe('PLN_test123');
});

function captureInitializedPayload(): stdClass
{
    $captured = new stdClass();
    $captured->value = [];

    $transactionMock = Mockery::mock();
    $transactionMock->shouldReceive('initialize')
        ->once()
        ->withArgs(function (array $payload) use ($captured): bool {
            $captured->value = $payload;

            return true;
        })
        ->andReturn((object) [
            'status' => true,
            'data' => (object) [
                'reference' => 'reference',
                'authorization_url' => 'someplace-on-the-internet',
            ],
        ]);

    $paystackHelperMock = Mockery::mock(PaystackHelper::class);
    $paystackHelperMock->transaction = $transactionMock;

    app()->bind(PaystackHelper::class, fn () => $paystackHelperMock);

    return $captured;
}
