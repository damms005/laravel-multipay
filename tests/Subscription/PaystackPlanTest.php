<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User;
use Yabacon\Paystack as PaystackHelper;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\SubscriptionService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;

beforeEach(function () {
    config()->set('laravel-multipay.paystack_secret_key', 'sk_test_xxx');
});

function mockPaystackVerify(string $reference, string $status = 'success'): void
{
    $verifyResponse = new stdClass();
    $verifyResponse->status = true;
    $verifyResponse->data = (object)[
        'status' => $status,
        'amount' => 100000,
        'created_at' => now()->toIso8601String(),
        'gateway_response' => $status === 'success' ? 'Successful' : 'Declined',
        'metadata' => null,
    ];

    $transactionMock = Mockery::mock();
    $transactionMock->shouldReceive('verify')->once()->andReturn($verifyResponse);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->transaction = $transactionMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);
}

function createPaystackPlan(string $interval = 'monthly'): PaymentPlan
{
    return PaymentPlan::create([
        'name' => "plan-{$interval}",
        'amount' => '1000',
        'interval' => $interval,
        'description' => 'description',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::class,
        'payment_handler_plan_id' => 'PLN_test123',
    ]);
}

function createPaystackPaymentForPlan(PaymentPlan $plan): \Damms005\LaravelMultipay\Models\Payment
{
    $payment = createPayment();
    $payment->update([
        'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
        'processor_transaction_reference' => 'ref_test123',
        'metadata' => ['payment_plan_id' => $plan->id],
    ]);

    return $payment;
}

it('creates plan and stores plan code from Paystack', function () {
    $planResponse = new stdClass();
    $planResponse->data = new stdClass();
    $planResponse->data->plan_code = 'PLN_test123';

    $planMock = Mockery::mock();
    $planMock->shouldReceive('create')->once()->andReturn($planResponse);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->plan = $planMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    (new SubscriptionService())
        ->createPaymentPlan(new Paystack(), 'plan', '1000', 'monthly', 'description', 'NGN');

    $this->assertDatabaseHas('payment_plans', [
        'name' => 'plan',
        'amount' => '1000',
        'interval' => 'monthly',
        'description' => 'description',
        'currency' => 'NGN',
        'payment_handler_fqcn' => Paystack::getUniquePaymentHandlerName(),
        'payment_handler_plan_id' => 'PLN_test123',
    ]);
});

it('sends plan code and converted amount when initializing subscription', function () {
    $plan = createPaystackPlan();

    $trxResponse = new stdClass();
    $trxResponse->status = true;
    $trxResponse->data = new stdClass();
    $trxResponse->data->authorization_url = 'https://checkout.paystack.com/test';
    $trxResponse->data->reference = 'PSK_REF_001';

    $transactionMock = Mockery::mock();
    $transactionMock->shouldReceive('initialize')
        ->once()
        ->withArgs(function ($payload) {
            return $payload['plan'] === 'PLN_test123'
                && $payload['amount'] == 100000
                && $payload['email'] === 'test@example.com'
                && isset($payload['reference']);
        })
        ->andReturn($trxResponse);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->transaction = $transactionMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    $user = new User();
    $user->email = 'test@example.com';

    (new SubscriptionService())->subscribeToPlan(new Paystack(), $user, $plan, 'http://localhost');

    $this->assertDatabaseHas('payments', [
        'metadata' => json_encode(['payment_plan_id' => $plan->id]),
    ]);

    $this->assertDatabaseMissing('subscriptions', [
        'payment_plan_id' => $plan->id,
    ]);
});

it('creates subscription with correct next_payment_due_date per interval', function (string $interval, Closure $expectedDate) {
    $plan = createPaystackPlan($interval);
    createPaystackPaymentForPlan($plan);

    mockPaystackVerify('ref_test123');

    (new Paystack())->processValueForTransaction('ref_test123');

    $subscription = DB::table('subscriptions')->where('payment_plan_id', $plan->id)->first();
    expect($subscription)->not->toBeNull();
    expect(Carbon::parse($subscription->next_payment_due_date)->format('Y-m-d'))
        ->toBe($expectedDate()->format('Y-m-d'));
})->with([
    'monthly' => ['monthly', fn () => now()->addMonth()],
    'quarterly' => ['quarterly', fn () => now()->addMonths(3)],
    'biannually' => ['biannually', fn () => now()->addMonths(6)],
    'yearly' => ['yearly', fn () => now()->addYear()],
]);

it('does not create subscription when payment fails', function () {
    $plan = createPaystackPlan();
    createPaystackPaymentForPlan($plan);

    mockPaystackVerify('ref_test123', 'failed');

    (new Paystack())->processValueForTransaction('ref_test123');

    $this->assertDatabaseCount('subscriptions', 0);
});

it('does not create subscription for non-subscription payments', function () {
    $payment = createPayment();
    $payment->update([
        'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
        'processor_transaction_reference' => 'ref_regular',
        'metadata' => ['some_key' => 'some_value'],
    ]);

    mockPaystackVerify('ref_regular');

    (new Paystack())->processValueForTransaction('ref_regular');

    $this->assertDatabaseCount('subscriptions', 0);
});

it('throws exception for unsupported interval', function () {
    $plan = createPaystackPlan('weekly');
    createPaystackPaymentForPlan($plan);

    mockPaystackVerify('ref_test123');

    (new Paystack())->processValueForTransaction('ref_test123');
})->throws(Exception::class, 'Unknown interval weekly');

it('accepts custom transaction reference and metadata when subscribing', function () {
    $plan = createPaystackPlan();

    $trxResponse = new stdClass();
    $trxResponse->status = true;
    $trxResponse->data = new stdClass();
    $trxResponse->data->authorization_url = 'https://checkout.paystack.com/test';
    $trxResponse->data->reference = 'CUSTOM-REF-001';

    $capturedPayload = null;
    $transactionMock = Mockery::mock();
    $transactionMock->shouldReceive('initialize')
        ->once()
        ->withArgs(function ($payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        })
        ->andReturn($trxResponse);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->transaction = $transactionMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    $user = new User();
    $user->email = 'custom@example.com';

    (new SubscriptionService())->subscribeToPlan(
        new Paystack(),
        $user,
        $plan,
        'http://localhost',
        'CUSTOM-REF-001',
        ['order_id' => 42]
    );

    expect($capturedPayload['reference'])->toBe('CUSTOM-REF-001');

    $payment = \Damms005\LaravelMultipay\Models\Payment::where('transaction_reference', 'CUSTOM-REF-001')->first();
    expect($payment)->not->toBeNull()
        ->and($payment->processor_transaction_reference)->toBe('CUSTOM-REF-001');

    $metadata = (array) $payment->metadata;
    expect($metadata['payment_plan_id'])->toBe($plan->id);
    expect($metadata['order_id'])->toBe(42);
});

it('resolves active subscription and returns null for expired or missing', function () {
    $plan = createPaystackPlan();

    $activeUser = new User();
    $activeUser->id = 1;

    $expiredUser = new User();
    $expiredUser->id = 2;

    $noSubUser = new User();
    $noSubUser->id = 999;

    Subscription::create([
        'user_id' => $activeUser->id,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->addMonth(),
    ]);

    Subscription::create([
        'user_id' => $expiredUser->id,
        'payment_plan_id' => $plan->id,
        'next_payment_due_date' => now()->subDay(),
    ]);

    $active = SubscriptionService::getActiveSubscriptionFor($activeUser, $plan);
    expect($active)->not->toBeNull()
        ->and($active->payment_plan_id)->toBe($plan->id);

    expect(SubscriptionService::getActiveSubscriptionFor($expiredUser, $plan))->toBeNull();
    expect(SubscriptionService::getActiveSubscriptionFor($noSubUser, $plan))->toBeNull();
});

it('finds existing plan instead of creating duplicate via findOrCreatePaymentPlan', function () {
    $planResponse = new stdClass();
    $planResponse->data = new stdClass();
    $planResponse->data->plan_code = 'PLN_existing001';

    $planMock = Mockery::mock();
    $planMock->shouldReceive('create')->once()->andReturn($planResponse);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->plan = $planMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    $handler = new Paystack();

    $first = SubscriptionService::findOrCreatePaymentPlan($handler, '5000', 'monthly', 'Test plan', 'NGN');

    expect($first->amount)->toBe('5000')
        ->and($first->interval)->toBe('monthly')
        ->and($first->currency)->toBe('NGN');

    $second = SubscriptionService::findOrCreatePaymentPlan($handler, '5000', 'monthly', 'Test plan', 'NGN');

    expect($second->id)->toBe($first->id);

    $this->assertDatabaseCount('payment_plans', 1);
});

it('uses displayAmount for original_amount_displayed_to_user when provided', function () {
    $plan = createPaystackPlan();

    $trxResponse = new stdClass();
    $trxResponse->status = true;
    $trxResponse->data = new stdClass();
    $trxResponse->data->authorization_url = 'https://checkout.paystack.com/test';
    $trxResponse->data->reference = 'DISPLAY-REF-001';

    $transactionMock = Mockery::mock();
    $transactionMock->shouldReceive('initialize')->once()->andReturn($trxResponse);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->transaction = $transactionMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    $user = new User();
    $user->email = 'display@example.com';

    (new SubscriptionService())->subscribeToPlan(
        new Paystack(),
        $user,
        $plan,
        'http://localhost',
        'DISPLAY-REF-001',
        null,
        '3500',
    );

    $payment = \Damms005\LaravelMultipay\Models\Payment::where('transaction_reference', 'DISPLAY-REF-001')->first();
    expect($payment)->not->toBeNull()
        ->and((string) $payment->original_amount_displayed_to_user)->toBe('3500');
});
