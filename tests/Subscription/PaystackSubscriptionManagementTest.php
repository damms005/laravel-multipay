<?php

use Illuminate\Foundation\Auth\User;
use Yabacon\Paystack as PaystackHelper;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\SubscriptionService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Exceptions\SubscriptionManagementException;

beforeEach(function () {
    config()->set('laravel-multipay.paystack_secret_key', 'sk_test_xxx');
});

function managementPlan(): PaymentPlan
{
    return PaymentPlan::create([
        'name' => 'managed-plan',
        'amount' => '1000',
        'interval' => 'monthly',
        'description' => 'description',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::class,
        'payment_handler_plan_id' => 'PLN_managed',
    ]);
}

function managedSubscription(array $attributes = []): Subscription
{
    return Subscription::create(array_merge([
        'user_id' => 1,
        'payment_plan_id' => managementPlan()->id,
        'next_payment_due_date' => now()->addMonth(),
        'payment_handler_subscription_code' => 'SUB_abc123',
        'payment_handler_email_token' => 'tok_xyz789',
        'status' => Subscription::STATUS_ACTIVE,
    ], $attributes));
}

function mockPaystackSubscriptionCall(string $method, array $expectedPayload, bool $status = true, string $message = 'done', ?object $data = null): void
{
    $response = new stdClass();
    $response->status = $status;
    $response->message = $message;
    $response->data = $data;

    $subscriptionMock = Mockery::mock();
    $subscriptionMock->shouldReceive($method)
        ->once()
        ->with($expectedPayload)
        ->andReturn($response);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->subscription = $subscriptionMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);
}

it('disables the subscription on Paystack and marks it paused when pausing', function () {
    $subscription = managedSubscription();

    mockPaystackSubscriptionCall('disable', ['code' => 'SUB_abc123', 'token' => 'tok_xyz789']);

    $result = SubscriptionService::pauseSubscription(new Paystack(), $subscription);

    expect($result->status)->toBe(Subscription::STATUS_PAUSED)
        ->and($result->isPaused())->toBeTrue();

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'status' => Subscription::STATUS_PAUSED,
    ]);
});

it('disables the subscription on Paystack and marks it cancelled when cancelling', function () {
    $subscription = managedSubscription();

    mockPaystackSubscriptionCall('disable', ['code' => 'SUB_abc123', 'token' => 'tok_xyz789']);

    $result = SubscriptionService::cancelSubscription(new Paystack(), $subscription);

    expect($result->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($result->isCancelled())->toBeTrue();
});

it('enables the subscription on Paystack and marks it active when resuming a paused subscription', function () {
    $subscription = managedSubscription(['status' => Subscription::STATUS_PAUSED]);

    mockPaystackSubscriptionCall('enable', ['code' => 'SUB_abc123', 'token' => 'tok_xyz789']);

    $result = SubscriptionService::resumeSubscription(new Paystack(), $subscription);

    expect($result->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($result->isActive())->toBeTrue();
});

it('refuses to resume a subscription that is not paused', function () {
    $subscription = managedSubscription(['status' => Subscription::STATUS_ACTIVE]);

    SubscriptionService::resumeSubscription(new Paystack(), $subscription);
})->throws(SubscriptionManagementException::class, 'cannot be resumed');

it('refuses to manage a subscription that has no provider code or token', function () {
    $subscription = managedSubscription([
        'payment_handler_subscription_code' => null,
        'payment_handler_email_token' => null,
    ]);

    SubscriptionService::pauseSubscription(new Paystack(), $subscription);
})->throws(SubscriptionManagementException::class, 'missing the provider subscription code');

it('refuses to manage when the handler does not support subscription management', function () {
    $subscription = managedSubscription();

    $handler = Mockery::mock(PaymentHandlerInterface::class);
    $handler->shouldReceive('getUniquePaymentHandlerName')->andReturn('NonManaging');

    SubscriptionService::pauseSubscription($handler, $subscription);
})->throws(SubscriptionManagementException::class, 'does not support subscription management');

it('throws when Paystack rejects the disable request', function () {
    $subscription = managedSubscription();

    mockPaystackSubscriptionCall(
        'disable',
        ['code' => 'SUB_abc123', 'token' => 'tok_xyz789'],
        status: false,
        message: 'Invalid subscription',
    );

    SubscriptionService::pauseSubscription(new Paystack(), $subscription);
})->throws(Exception::class, 'Invalid subscription');

it('stores provider subscription code and email token via recordProviderSubscriptionData', function () {
    $subscription = managedSubscription([
        'payment_handler_subscription_code' => null,
        'payment_handler_email_token' => null,
    ]);

    $result = SubscriptionService::recordProviderSubscriptionData($subscription, 'SUB_new', 'tok_new');

    expect($result->payment_handler_subscription_code)->toBe('SUB_new')
        ->and($result->payment_handler_email_token)->toBe('tok_new');

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'payment_handler_subscription_code' => 'SUB_new',
        'payment_handler_email_token' => 'tok_new',
    ]);
});

it('maps subscription details returned by Paystack fetch', function () {
    $data = (object) [
        'subscription_code' => 'SUB_abc123',
        'email_token' => 'tok_xyz789',
        'status' => 'active',
        'next_payment_date' => '2026-07-01T00:00:00.000Z',
    ];

    mockPaystackSubscriptionCall('fetch', ['id' => 'SUB_abc123'], data: $data);

    $details = (new Paystack())->getSubscriptionDetails('SUB_abc123');

    expect($details)->toBe([
        'subscription_code' => 'SUB_abc123',
        'email_token' => 'tok_xyz789',
        'status' => 'active',
        'next_payment_date' => '2026-07-01T00:00:00.000Z',
    ]);
});

it('excludes paused and cancelled subscriptions from getActiveSubscriptionFor', function (string $status, bool $expectedActive) {
    $plan = managementPlan();

    $user = new User();
    $user->id = 1;

    Subscription::create([
        'user_id' => 1,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
        'status' => $status,
    ]);

    $active = SubscriptionService::getActiveSubscriptionFor($user, $plan);

    expect($active !== null)->toBe($expectedActive);
})->with([
    'active is returned' => [Subscription::STATUS_ACTIVE, true],
    'paused is excluded' => [Subscription::STATUS_PAUSED, false],
    'cancelled is excluded' => [Subscription::STATUS_CANCELLED, false],
]);
