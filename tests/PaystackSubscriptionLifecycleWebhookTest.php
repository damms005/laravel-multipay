<?php

use Damms005\LaravelMultipay\Events\SubscriptionCancelled;
use Damms005\LaravelMultipay\Events\SubscriptionRenewalFailed;
use Damms005\LaravelMultipay\Events\SubscriptionSuspended;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Webhooks\Paystack\InvoicePaymentFailed;
use Damms005\LaravelMultipay\Webhooks\Paystack\InvoiceUpdate;
use Damms005\LaravelMultipay\Webhooks\Paystack\SubscriptionCreate;
use Damms005\LaravelMultipay\Webhooks\Paystack\SubscriptionDisable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

function lifecyclePlan(): PaymentPlan
{
    return PaymentPlan::create([
        'name' => 'lifecycle-plan-' . uniqid(),
        'amount' => '5000',
        'interval' => 'monthly',
        'description' => 'desc',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_lifecycle',
    ]);
}

it('reconciles the subscription code on a subscription.create webhook without emitting a payment event', function () {
    Event::fake();

    $plan = lifecyclePlan();
    $subscription = Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
    ]);

    $request = Request::create('/webhook', 'POST', [
        'event' => 'subscription.create',
        'data' => [
            'subscription_code' => 'SUB_new',
            'email_token' => 'tok_new',
            'plan' => ['plan_code' => 'PLN_lifecycle'],
            'customer' => ['customer_code' => 'CUS_x', 'email' => 'a@b.com'],
        ],
    ]);

    $handler = new SubscriptionCreate();
    expect($handler->isHandlerFor($request))->toBeTrue()
        ->and($handler->handle($request))->toBeNull();

    $subscription->refresh();

    expect($subscription->payment_handler_subscription_code)->toBe('SUB_new')
        ->and($subscription->payment_handler_email_token)->toBe('tok_new')
        ->and($subscription->status)->toBe(Subscription::STATUS_ACTIVE);

    Event::assertNotDispatched(\Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent::class);
});

it('marks a subscription cancelled and emits SubscriptionCancelled on subscription.disable', function () {
    Event::fake();

    $plan = lifecyclePlan();
    $subscription = Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_cancel',
    ]);

    $request = Request::create('/webhook', 'POST', [
        'event' => 'subscription.disable',
        'data' => ['subscription_code' => 'SUB_cancel'],
    ]);

    $handler = new SubscriptionDisable();
    expect($handler->isHandlerFor($request))->toBeTrue()
        ->and($handler->handle($request))->toBeNull();

    expect($subscription->refresh()->status)->toBe(Subscription::STATUS_CANCELLED);

    Event::assertDispatched(SubscriptionCancelled::class);
});

it('emits SubscriptionRenewalFailed on invoice.payment_failed', function () {
    Event::fake();

    $plan = lifecyclePlan();
    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_fail',
    ]);

    $request = Request::create('/webhook', 'POST', [
        'event' => 'invoice.payment_failed',
        'data' => [
            'subscription' => ['subscription_code' => 'SUB_fail'],
            'transaction' => ['reference' => 'ref_fail'],
        ],
    ]);

    $handler = new InvoicePaymentFailed();
    expect($handler->isHandlerFor($request))->toBeTrue()
        ->and($handler->handle($request))->toBeNull();

    Event::assertDispatched(SubscriptionRenewalFailed::class);
});

it('emits SubscriptionSuspended when invoice.update reports the subscription in a grace state', function () {
    Event::fake();

    $plan = lifecyclePlan();
    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_suspend',
    ]);

    $request = Request::create('/webhook', 'POST', [
        'event' => 'invoice.update',
        'data' => [
            'subscription' => ['subscription_code' => 'SUB_suspend', 'status' => 'attention'],
        ],
    ]);

    $handler = new InvoiceUpdate();
    expect($handler->isHandlerFor($request))->toBeTrue()
        ->and($handler->handle($request))->toBeNull();

    Event::assertDispatched(SubscriptionSuspended::class);
});

it('ignores invoice.update when the subscription is still active', function () {
    Event::fake();

    $plan = lifecyclePlan();
    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_active',
    ]);

    $request = Request::create('/webhook', 'POST', [
        'event' => 'invoice.update',
        'data' => [
            'subscription' => ['subscription_code' => 'SUB_active', 'status' => 'active'],
        ],
    ]);

    (new InvoiceUpdate())->handle($request);

    Event::assertNotDispatched(SubscriptionSuspended::class);
});
