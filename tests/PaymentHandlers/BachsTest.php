<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Bachs;

beforeEach(function () {
    config()->set('laravel-multipay.bachs.secret_key', 'sk_sandbox_test');
    config()->set('laravel-multipay.bachs.base_url', 'https://sandbox-api.bachs.io');
    config()->set('laravel-multipay.bachs.webhook_signing_secret', 'whsec_test');
    config()->set('laravel-multipay.bachs.product_cache.enabled', true);

    Cache::flush();

    $this->payment = createPayment();
    $this->payment->update(['payment_processor_name' => 'Bachs']);
    $this->payment->refresh();
});

function bachsCheckoutResponse(string $checkoutId = 'checkout-uuid-1', string $url = 'https://sandbox.bachs.io/pay/checkout-uuid-1'): array
{
    return [
        'checkout_id' => $checkoutId,
        'checkout_url' => $url,
        'status' => 'open',
        'amount' => '500.00',
        'currency' => 'USD',
        'reference' => 'ref',
    ];
}

function bachsWebhookRequest(array $payload, string $signingSecret = 'whsec_test', ?string $timestamp = null, ?string $signatureOverride = null): Request
{
    $timestamp ??= (string) time();
    $body = json_encode($payload);
    $signature = $signatureOverride ?? hash_hmac('sha256', "{$timestamp}.{$body}", $signingSecret);

    return Request::create('/payment/completed/notify', 'POST', [], [], [], [
        'HTTP_X_BACHS_SIGNATURE' => $signature,
        'HTTP_X_BACHS_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('creates a checkout session and redirects to the checkout url', function () {
    $this->payment->update(['metadata' => ['bachs_product_id' => 'prod_explicit']]);

    Http::fake([
        'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response(bachsCheckoutResponse()),
    ]);

    $response = (new Bachs())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    expect($response->getTargetUrl())->toBe('https://sandbox.bachs.io/pay/checkout-uuid-1');

    $this->payment->refresh();
    expect($this->payment->processor_transaction_reference)->toBe('checkout-uuid-1')
        ->and($this->payment->metadata['bachs_checkout_url'])->toBe('https://sandbox.bachs.io/pay/checkout-uuid-1');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/checkout-sessions')
            && $request['product_cart'] === [['product_id' => 'prod_explicit', 'quantity' => 1]]
            && $request['customer']['email'] === 'user@gmail.com'
            && $request['reference'] === $this->payment->transaction_reference
            && $request['success_url'] === 'https://app.test/done';
    });
});

it('skips product creation when an explicit bachs_product_id is supplied', function () {
    $this->payment->update(['metadata' => ['bachs_product_id' => 'prod_explicit']]);

    Http::fake([
        'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response(bachsCheckoutResponse()),
    ]);

    (new Bachs())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/products'));
});

it('finds-or-creates a fixed product and sends the amount as a decimal string', function () {
    Http::fake([
        'sandbox-api.bachs.io/v1/products*' => Http::sequence()
            ->push(['data' => [], 'next_cursor' => null])
            ->push(['id' => 'prod_created']),
        'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response(bachsCheckoutResponse()),
    ]);

    (new Bachs())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/products')
            && $request['price']['price_type'] === 'fixed'
            && $request['price']['amount'] === '500.00'
            && $request['price']['currency'] === 'USD';
    });
});

it('reuses an existing fixed product found in the products list and does not create one', function () {
    $name = $this->payment->transaction_description;

    Http::fake([
        'sandbox-api.bachs.io/v1/products*' => Http::response([
            'items' => [
                ['id' => 'prod_existing', 'name' => $name, 'price' => ['price_type' => 'fixed', 'amount' => '500.00', 'currency' => 'USD']],
            ],
            'pagination' => ['next_cursor' => null],
        ]),
        'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response(bachsCheckoutResponse()),
    ]);

    (new Bachs())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/products'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout-sessions')
        && $request['product_cart'][0]['product_id'] === 'prod_existing');
});

it('reuses the cached product id and does not create the product twice', function () {
    Http::fake([
        'sandbox-api.bachs.io/v1/products*' => Http::sequence()
            ->push(['data' => [], 'next_cursor' => null])
            ->push(['id' => 'prod_created']),
        'sandbox-api.bachs.io/v1/checkout-sessions' => Http::response(bachsCheckoutResponse()),
    ]);

    (new Bachs())->proceedToPaymentGateway($this->payment, 'https://app.test/done');
    (new Bachs())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    $productCreations = 0;
    Http::assertSent(function ($request) use (&$productCreations) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/products')) {
            $productCreations++;
        }

        return true;
    });

    expect($productCreations)->toBe(1);
});

it('confirms a checkout session and marks the payment successful', function () {
    $this->payment->update(['processor_transaction_reference' => 'checkout-uuid-1']);

    Http::fake([
        'sandbox-api.bachs.io/v1/checkout-sessions/checkout-uuid-1' => Http::response([
            'checkout_id' => 'checkout-uuid-1',
            'status' => 'complete',
            'payment_status' => 'paid',
            'reference' => $this->payment->transaction_reference,
            'amount' => '500.00',
            'currency' => 'USD',
            'charge' => ['charge_id' => 'chrg_1', 'status' => 'succeeded', 'amount' => '500.00'],
        ]),
    ]);

    $request = Request::create('/payment/completed', 'GET', ['checkout_id' => 'checkout-uuid-1']);

    $payment = (new Bachs())->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($request);

    expect($payment)->not->toBeNull()
        ->and((bool) $payment->is_success)->toBeTrue()
        ->and($payment->metadata['bachs_charge_id'])->toBe('chrg_1');
});

it('returns null when confirming a response that carries no checkout_id', function () {
    $request = Request::create('/payment/completed', 'GET');

    expect((new Bachs())->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($request))->toBeNull();
});

it('gives value on a succeeded charge when requerying', function () {
    $this->payment->update(['metadata' => ['bachs_charge_id' => 'chrg_1']]);

    Http::fake([
        'sandbox-api.bachs.io/v1/payments/charges/chrg_1' => Http::response([
            'charge_id' => 'chrg_1',
            'status' => 'SUCCEEDED',
            'amount' => '500.00',
            'currency' => 'USD',
        ]),
    ]);

    $reQuery = (new Bachs())->reQuery($this->payment);

    expect((bool) $reQuery->payment->is_success)->toBeTrue();
});

it('keeps a pending charge requeryable when requerying', function () {
    $this->payment->update(['metadata' => ['bachs_charge_id' => 'chrg_1']]);

    Http::fake([
        'sandbox-api.bachs.io/v1/payments/charges/chrg_1' => Http::response([
            'charge_id' => 'chrg_1',
            'status' => 'PROCESSING',
        ]),
    ]);

    $reQuery = (new Bachs())->reQuery($this->payment);

    expect($reQuery->payment->is_success)->toBeNull();
});

it('marks a failed charge as unsuccessful when requerying', function () {
    $this->payment->update(['metadata' => ['bachs_charge_id' => 'chrg_1']]);

    Http::fake([
        'sandbox-api.bachs.io/v1/payments/charges/chrg_1' => Http::response([
            'charge_id' => 'chrg_1',
            'status' => 'FAILED',
        ]),
    ]);

    $reQuery = (new Bachs())->reQuery($this->payment);

    expect((bool) $reQuery->payment->is_success)->toBeFalse()
        ->and($reQuery->payment->is_success)->not->toBeNull();
});

it('detects an unsettled payment', function () {
    expect((new Bachs())->paymentIsUnsettled($this->payment))->toBeTrue();
});

it('resumes an unsettled payment by redirecting to the stored checkout url', function () {
    $this->payment->update(['metadata' => ['bachs_checkout_url' => 'https://sandbox.bachs.io/pay/resume']]);

    $response = (new Bachs())->resumeUnsettledPayment($this->payment->refresh());

    expect($response->getTargetUrl())->toBe('https://sandbox.bachs.io/pay/resume');
});

it('accepts a webhook with a valid signature and marks the payment successful', function () {
    $this->payment->update(['processor_transaction_reference' => 'checkout-uuid-1']);

    $request = bachsWebhookRequest([
        'id' => 'evt_1',
        'type' => 'collection.succeeded',
        'data' => [
            'charge_id' => 'chrg_1',
            'checkout_id' => 'checkout-uuid-1',
            'reference' => $this->payment->transaction_reference,
            'status' => 'succeeded',
            'amount' => '500.00',
            'currency' => 'USD',
        ],
    ]);

    $payment = (new Bachs())->handleExternalWebhookRequest($request);

    expect((bool) $payment->is_success)->toBeTrue()
        ->and($payment->metadata['bachs_charge_id'])->toBe('chrg_1');
});

it('marks the payment as failed on a collection.failed webhook', function () {
    $request = bachsWebhookRequest([
        'id' => 'evt_2',
        'type' => 'collection.failed',
        'data' => [
            'charge_id' => 'chrg_2',
            'reference' => $this->payment->transaction_reference,
            'status' => 'failed',
        ],
    ]);

    $payment = (new Bachs())->handleExternalWebhookRequest($request);

    expect((bool) $payment->is_success)->toBeFalse();
});

it('rejects a webhook whose signature does not match', function () {
    $request = bachsWebhookRequest(
        ['type' => 'collection.succeeded', 'data' => ['reference' => $this->payment->transaction_reference]],
        signatureOverride: 'deadbeef',
    );

    (new Bachs())->handleExternalWebhookRequest($request);
})->throws(Exception::class, 'signature verification failed');

it('rejects a webhook whose timestamp is stale', function () {
    $request = bachsWebhookRequest(
        ['type' => 'collection.succeeded', 'data' => ['reference' => $this->payment->transaction_reference]],
        timestamp: (string) (time() - 1000),
    );

    (new Bachs())->handleExternalWebhookRequest($request);
})->throws(Exception::class, 'timestamp is outside the allowed tolerance');
