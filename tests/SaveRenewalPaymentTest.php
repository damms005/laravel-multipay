<?php

use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;

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

it('credits a renewal to the paying customer and to nobody when no customer matches', function () {
    DB::table('users')->insert(['id' => 2, 'email' => 'second@example.com']);

    $plan = PaymentPlan::create([
        'name' => 'shared-renew-plan',
        'amount' => '2500',
        'interval' => 'monthly',
        'description' => 'desc',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_shared_renew',
    ]);

    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_payer',
        'metadata' => ['customer_code' => 'CUS_payer'],
    ]);

    Subscription::create([
        'user_id' => 2,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_newest',
        'metadata' => ['customer_code' => 'CUS_newest'],
    ]);

    $renewalFrom = fn (string $reference, string $customerCode, string $email) => SubscriptionService::saveRenewalPayment([
        'event' => 'charge.success',
        'data' => [
            'reference' => $reference,
            'amount' => 250000,
            'currency' => 'NGN',
            'plan' => ['plan_code' => 'PLN_shared_renew'],
            'customer' => ['customer_code' => $customerCode, 'email' => $email],
        ],
    ]);

    $payerRenewal = $renewalFrom('ref_payer', 'CUS_payer', 'user@gmail.com');
    $unknownRenewal = $renewalFrom('ref_unknown', 'CUS_unknown', 'unknown@example.com');

    expect((int) $payerRenewal->user_id)->toBe(1)
        ->and($payerRenewal->payment_handler_subscription_code)->toBe('SUB_payer')
        ->and($unknownRenewal->user_id)->toBeNull()
        ->and($unknownRenewal->payment_handler_subscription_code)->toBeNull();
});
