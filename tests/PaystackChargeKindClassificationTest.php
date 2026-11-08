<?php

use Damms005\LaravelMultipay\Enums\ChargeKind;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;

beforeEach(function () {
    config()->set('laravel-multipay.paystack_secret_key', 'sk_test_x');
});

it('classifies a charge without a plan as a plain one-off', function () {
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_oneoff',
            'amount' => 500000,
            'customer' => ['customer_code' => 'CUS_oneoff'],
        ],
    ];

    expect((new Paystack())->classifyCharge($payload))->toBe(ChargeKind::OneOff);
});

it('classifies the first charge on a plan as an initial subscription charge', function () {
    $plan = PaymentPlan::create([
        'name' => 'starter-plan',
        'amount' => '5000',
        'interval' => 'monthly',
        'description' => 'test',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_initial',
    ]);

    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_initial',
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_initial',
            'plan' => ['plan_code' => 'PLN_initial'],
            'customer' => ['customer_code' => 'CUS_a'],
        ],
    ];

    expect((new Paystack())->classifyCharge($payload))->toBe(ChargeKind::Initial);
});

it('classifies a repeat charge on a plan as a renewal once a prior successful payment exists', function () {
    $plan = PaymentPlan::create([
        'name' => 'renewing-plan',
        'amount' => '5000',
        'interval' => 'monthly',
        'description' => 'test',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_renew',
    ]);

    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_renew',
        'metadata' => ['customer_code' => 'CUS_a'],
    ]);

    Payment::create([
        'user_id' => 1,
        'transaction_reference' => 'ref_prior',
        'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
        'transaction_currency' => 'NGN',
        'transaction_description' => 'first cycle',
        'original_amount_displayed_to_user' => 5000,
        'is_success' => 1,
        'metadata' => ['payment_plan_id' => $plan->id],
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_renewal',
            'plan' => ['plan_code' => 'PLN_renew'],
            'customer' => ['customer_code' => 'CUS_a'],
        ],
    ];

    expect((new Paystack())->classifyCharge($payload))->toBe(ChargeKind::Renewal);
});

it('classifies a first charge as initial when its own payment is already stored as successful', function () {
    $plan = PaymentPlan::create([
        'name' => 'first-charge-plan',
        'amount' => '5000',
        'interval' => 'monthly',
        'description' => 'test',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_first',
    ]);

    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
    ]);

    Payment::create([
        'user_id' => 1,
        'transaction_reference' => 'ref_first',
        'processor_transaction_reference' => 'ref_first',
        'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
        'transaction_currency' => 'NGN',
        'transaction_description' => 'first cycle',
        'original_amount_displayed_to_user' => 5000,
        'is_success' => 1,
        'metadata' => ['payment_plan_id' => $plan->id],
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_first',
            'plan' => ['plan_code' => 'PLN_first'],
            'customer' => ['customer_code' => 'CUS_first', 'email' => 'user@gmail.com'],
        ],
    ];

    expect((new Paystack())->classifyCharge($payload))->toBe(ChargeKind::Initial);
});

it('classifies a new customer on a plan as initial when another customer already renews on it', function () {
    $plan = PaymentPlan::create([
        'name' => 'shared-plan',
        'amount' => '5000',
        'interval' => 'monthly',
        'description' => 'test',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_shared',
    ]);

    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_existing',
        'metadata' => ['customer_code' => 'CUS_existing'],
    ]);

    Payment::create([
        'user_id' => 1,
        'transaction_reference' => 'ref_existing',
        'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
        'transaction_currency' => 'NGN',
        'transaction_description' => 'first cycle',
        'original_amount_displayed_to_user' => 5000,
        'is_success' => 1,
        'metadata' => ['payment_plan_id' => $plan->id],
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_newcomer',
            'plan' => ['plan_code' => 'PLN_shared'],
            'customer' => ['customer_code' => 'CUS_newcomer', 'email' => 'newcomer@example.com'],
        ],
    ];

    expect((new Paystack())->classifyCharge($payload))->toBe(ChargeKind::Initial);
});
