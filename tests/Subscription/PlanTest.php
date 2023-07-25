<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;
use KingFlamez\Rave\Rave as FlutterwaveRave;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Services\SubscriptionService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Flutterwave;

describe('Flutterwave subscriptions', function () {

    beforeEach(function () {
        config()->set('laravel-multipay.flutterwave.publicKey', 'FLW_PUBLIC_KEY');
        config()->set('laravel-multipay.flutterwave.secretKey', 'FLW_SECRET_KEY');
        config()->set('laravel-multipay.flutterwave.secretHash', 'FLW_SECRET_HASH');
    });

    it('creates Flutterwave plan', function () {
        $mock = mock(Flutterwave::class);
        $mock->makePartial()
            ->expects('createPaymentPlan')
            ->andReturn('123');

        app()->bind(SubscriptionService::class, fn () => $mock);

        (new SubscriptionService())
            ->createPaymentPlan($mock, 'plan', '1000', 'monthly', 'description', 'NGN');

        $this->assertDatabaseHas('payment_plans', [
            'name' => 'plan',
            'amount' => '1000',
            'interval' => 'monthly',
            'description' => 'description',
            'currency' => 'NGN',
            'payment_handler_fqcn' => $mock::class,
            'payment_handler_plan_id' => '123',
        ]);
    });

    it('initializes subscription for Flutterwave plan', function () {
        $plan = PaymentPlan::create([
            'name' => 'plan',
            'amount' => '1000',
            'interval' => 'monthly',
            'description' => 'description',
            'currency' => 'NGN',
            'payment_handler_fqcn' => Flutterwave::class,
            'payment_handler_plan_id' => '123',
        ]);

        $raveMock = mock(FlutterwaveRave::class);
        $raveMock
            ->expects('initializePayment')
            ->andReturn(['status' => 'success', 'data' => ['link' => 'http://localhost']]);

        app()->bind('laravelrave', fn ($app) => $raveMock);

        (new SubscriptionService())->subscribeToPlan(new Flutterwave, new User(), $plan, 'localhost');

        $this->assertDatabaseHas('payments', [
            'metadata' => json_encode(['payment_plan_id' => $plan->id]),
        ]);

        // assert it does not create a subscription yet
        $this->assertDatabaseMissing('subscriptions', [
            'payment_plan_id' => $plan->id,
        ]);
    });

    it('subscribes user to Flutterwave plan upon receipt of payment webhook', function () {
        $plan = PaymentPlan::create([
            'name' => 'plan',
            'amount' => '1000',
            'interval' => 'monthly',
            'description' => 'description',
            'currency' => 'NGN',
            'payment_handler_fqcn' => Flutterwave::class,
            'payment_handler_plan_id' => '123',
        ]);

        $payment = createPayment();
        $payment->update([
            'transaction_reference' => 'abc-d',
            'metadata' => ['payment_plan_id' => $plan->id]
        ]);

        $raveMock = mock(FlutterwaveRave::class);
        $raveMock
            ->expects('verifyTransaction')
            ->andReturn(['data'  => [
                'amount' => $payment->original_amount_displayed_to_user,
                'created_at' => now()->toIso8601String(),
                'processor_response' => 'successful',
            ]]);

        app()->bind('laravelrave', fn ($app) => $raveMock);

        $this->post(route('payment.external-webhook-endpoint'), [
            'tx_ref' => 'abc-d',
            'status' => 'successful',
            'amount' => '1000',
            'currency' => 'NGN',
        ]);

        $this->assertDatabaseHas('subscriptions', [
            'payment_plan_id' => $plan->id,
            'next_payment_due_date' => now()->addMonth()->format('Y-m-d H:i:s'),
        ]);
    });
});

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
