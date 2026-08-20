<?php

use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Services\SubscriptionService;

it('materializes a renewal payment once and is idempotent on the reference', function () {
    $plan = PaymentPlan::create([
        'name' => 'renew-plan',
        'amount' => '5000',
        'interval' => 'monthly',
        'description' => 'desc',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_r',
    ]);

    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_r',
        'metadata' => ['customer_code' => 'CUS_r'],
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_renew_1',
            'amount' => 500000,
            'currency' => 'NGN',
            'plan' => ['plan_code' => 'PLN_r'],
            'customer' => ['customer_code' => 'CUS_r', 'email' => 'a@b.com'],
        ],
    ];

    $first = SubscriptionService::saveRenewalPayment($payload);
    $second = SubscriptionService::saveRenewalPayment($payload);

    expect($first->id)->toBe($second->id)
        ->and(Payment::where('transaction_reference', 'ref_renew_1')->count())->toBe(1)
        ->and((int) $first->original_amount_displayed_to_user)->toBe(5000)
        ->and($first->is_success)->toBeTruthy()
        ->and($first->payment_handler_subscription_code)->toBe('SUB_r');
});
