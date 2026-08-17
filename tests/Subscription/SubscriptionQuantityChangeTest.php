<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Yabacon\Paystack as PaystackHelper;
use Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity;
use Damms005\LaravelMultipay\Events\SubscriptionCodeReplaced;
use Damms005\LaravelMultipay\Exceptions\UnsupportedOperationException;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Bachs;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;
use Damms005\LaravelMultipay\ValueObjects\SubscriptionQuantityChange;

beforeEach(function () {
    config()->set('laravel-multipay.paystack_secret_key', 'sk_test_xxx');
    config()->set('laravel-multipay.polar.access_token', 'polar_oat_test');
    config()->set('laravel-multipay.polar.server', 'sandbox');
    config()->set('laravel-multipay.polar.base_url', 'https://sandbox-api.polar.sh');
    config()->set('laravel-multipay.bachs.secret_key', 'sk_sandbox_test');
    config()->set('laravel-multipay.bachs.base_url', 'https://sandbox-api.bachs.io');
});

it('advertises the subscription_quantity capability only on providers that support it', function () {
    expect((new Polar())->supports(SupportsSubscriptionQuantity::CAPABILITY))->toBeTrue()
        ->and((new Paystack())->supports(SupportsSubscriptionQuantity::CAPABILITY))->toBeTrue()
        ->and((new Bachs())->supports(SupportsSubscriptionQuantity::CAPABILITY))->toBeFalse();
});

it('changes a Polar subscription seat count in-place via PATCH with the mapped proration value', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/subscriptions/sub_polar_1' => Http::response([
            'id' => 'sub_polar_1',
            'status' => 'active',
            'seats' => 3,
            'current_period_start' => '2026-08-17T00:00:00Z',
            'prorated_amount' => 450,
        ]),
    ]);

    $result = (new Polar())->changeSubscriptionQuantity('sub_polar_1', 3);

    expect($result)->toBeInstanceOf(SubscriptionQuantityChange::class)
        ->and($result->newSubscriptionCode)->toBe('sub_polar_1')
        ->and($result->replacedPreviousCode)->toBeFalse()
        ->and($result->isAsync)->toBeFalse()
        ->and($result->effectiveFrom)->toBe('2026-08-17T00:00:00Z')
        ->and($result->proratedChargeAmount)->toBe('450');

    Http::assertSent(function ($request) {
        return $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/subscriptions/sub_polar_1')
            && $request['seats'] === 3
            && $request['proration_behavior'] === 'prorate';
    });
});

it('maps the none proration value to Polar next_period', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/subscriptions/sub_polar_1' => Http::response([
            'id' => 'sub_polar_1',
            'seats' => 2,
        ]),
    ]);

    (new Polar())->changeSubscriptionQuantity('sub_polar_1', 2, null, SupportsSubscriptionQuantity::PRORATION_NONE);

    Http::assertSent(fn ($request) => $request['proration_behavior'] === 'next_period');
});

it('rejects a Polar quantity below one', function () {
    (new Polar())->changeSubscriptionQuantity('sub_polar_1', 0);
})->throws(InvalidArgumentException::class, 'at least 1');

it('rejects an unknown proration behavior on Polar', function () {
    (new Polar())->changeSubscriptionQuantity('sub_polar_1', 2, null, 'weekly-magic');
})->throws(InvalidArgumentException::class, 'Unknown proration behavior');

it('bumps Paystack quantity via disable + create-plan + create-subscription and dispatches SubscriptionCodeReplaced', function () {
    Event::fake([SubscriptionCodeReplaced::class]);

    $fetchResponse = new stdClass();
    $fetchResponse->status = true;
    $fetchResponse->data = (object) [
        'subscription_code' => 'SUB_old_123',
        'email_token' => 'tok_stored',
        'customer' => (object) ['email' => 'parent@example.com'],
        'authorization' => (object) ['authorization_code' => 'AUTH_abc'],
        'plan' => (object) [
            'name' => 'Family plan',
            'amount' => 500000,
            'interval' => 'monthly',
            'currency' => 'NGN',
        ],
    ];

    $disableResponse = new stdClass();
    $disableResponse->status = true;
    $disableResponse->message = 'ok';

    $planCreateResponse = new stdClass();
    $planCreateResponse->status = true;
    $planCreateResponse->data = (object) ['plan_code' => 'PLN_new_x3'];

    $subCreateResponse = new stdClass();
    $subCreateResponse->status = true;
    $subCreateResponse->data = (object) [
        'subscription_code' => 'SUB_new_999',
        'next_payment_date' => '2026-09-17T00:00:00.000Z',
    ];

    $subscriptionMock = Mockery::mock();
    $subscriptionMock->shouldReceive('fetch')->once()->with(['id' => 'SUB_old_123'])->andReturn($fetchResponse);
    $subscriptionMock->shouldReceive('disable')->once()->with(['code' => 'SUB_old_123', 'token' => 'tok_stored'])->andReturn($disableResponse);
    $subscriptionMock->shouldReceive('create')->once()
        ->withArgs(function ($payload) {
            return $payload['customer'] === 'parent@example.com'
                && $payload['plan'] === 'PLN_new_x3'
                && $payload['authorization'] === 'AUTH_abc';
        })
        ->andReturn($subCreateResponse);

    $planMock = Mockery::mock();
    $planMock->shouldReceive('create')->once()
        ->withArgs(function ($payload) {
            return $payload['amount'] === 1500000
                && $payload['interval'] === 'monthly'
                && $payload['currency'] === 'NGN'
                && str_starts_with($payload['name'], 'Family plan x3');
        })
        ->andReturn($planCreateResponse);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->subscription = $subscriptionMock;
    $paystackMock->plan = $planMock;

    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    $result = (new Paystack())->changeSubscriptionQuantity('SUB_old_123', 3, 'tok_stored');

    expect($result->newSubscriptionCode)->toBe('SUB_new_999')
        ->and($result->replacedPreviousCode)->toBeTrue()
        ->and($result->isAsync)->toBeFalse()
        ->and($result->effectiveFrom)->toBe('2026-09-17T00:00:00.000Z');

    Event::assertDispatched(SubscriptionCodeReplaced::class, function (SubscriptionCodeReplaced $event) {
        return $event->previousSubscriptionCode === 'SUB_old_123'
            && $event->newSubscriptionCode === 'SUB_new_999'
            && $event->newQuantity === 3
            && $event->paymentHandlerName === Paystack::getUniquePaymentHandlerName();
    });
});

it('falls back to the fetched Paystack email_token when the caller did not pass one', function () {
    Event::fake([SubscriptionCodeReplaced::class]);

    $fetchResponse = new stdClass();
    $fetchResponse->status = true;
    $fetchResponse->data = (object) [
        'subscription_code' => 'SUB_a',
        'email_token' => 'tok_from_fetch',
        'customer' => (object) ['email' => 'x@y.com'],
        'authorization' => (object) ['authorization_code' => 'AUTH_x'],
        'plan' => (object) ['name' => 'p', 'amount' => 100, 'interval' => 'monthly', 'currency' => 'NGN'],
    ];

    $ok = new stdClass();
    $ok->status = true;
    $ok->message = 'ok';
    $ok->data = (object) ['plan_code' => 'PLN_y', 'subscription_code' => 'SUB_b'];

    $subscriptionMock = Mockery::mock();
    $subscriptionMock->shouldReceive('fetch')->once()->andReturn($fetchResponse);
    $subscriptionMock->shouldReceive('disable')->once()
        ->with(['code' => 'SUB_a', 'token' => 'tok_from_fetch'])
        ->andReturn($ok);
    $subscriptionMock->shouldReceive('create')->once()->andReturn($ok);

    $planMock = Mockery::mock();
    $planMock->shouldReceive('create')->once()->andReturn($ok);

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->subscription = $subscriptionMock;
    $paystackMock->plan = $planMock;
    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    (new Paystack())->changeSubscriptionQuantity('SUB_a', 2);
});

it('throws when Paystack disable fails and does not create a replacement plan or subscription', function () {
    Event::fake([SubscriptionCodeReplaced::class]);

    $fetchResponse = new stdClass();
    $fetchResponse->status = true;
    $fetchResponse->data = (object) [
        'subscription_code' => 'SUB_x',
        'email_token' => 'tok_x',
        'customer' => (object) ['email' => 'x@y.com'],
        'authorization' => (object) ['authorization_code' => 'AUTH_x'],
        'plan' => (object) ['name' => 'p', 'amount' => 100, 'interval' => 'monthly', 'currency' => 'NGN'],
    ];

    $disableFail = new stdClass();
    $disableFail->status = false;
    $disableFail->message = 'Subscription already inactive';

    $subscriptionMock = Mockery::mock();
    $subscriptionMock->shouldReceive('fetch')->once()->andReturn($fetchResponse);
    $subscriptionMock->shouldReceive('disable')->once()->andReturn($disableFail);
    $subscriptionMock->shouldNotReceive('create');

    $planMock = Mockery::mock();
    $planMock->shouldNotReceive('create');

    $paystackMock = Mockery::mock(PaystackHelper::class);
    $paystackMock->subscription = $subscriptionMock;
    $paystackMock->plan = $planMock;
    app()->bind(PaystackHelper::class, fn () => $paystackMock);

    try {
        (new Paystack())->changeSubscriptionQuantity('SUB_x', 2, 'tok_x');
        $this->fail('expected exception');
    } catch (Exception $exception) {
        expect($exception->getMessage())->toBe('Subscription already inactive');
    }

    Event::assertNotDispatched(SubscriptionCodeReplaced::class);
});

it('refuses to bump Bachs subscription quantity because Bachs does not expose the endpoint', function () {
    (new Bachs())->changeSubscriptionQuantity('sub_bachs_1', 3);
})->throws(UnsupportedOperationException::class, 'Bachs does not expose');
