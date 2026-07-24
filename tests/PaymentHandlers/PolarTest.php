<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;

beforeEach(function () {
    config()->set('laravel-multipay.polar.access_token', 'polar_oat_test');
    config()->set('laravel-multipay.polar.server', 'sandbox');
    config()->set('laravel-multipay.polar.base_url', 'https://sandbox-api.polar.sh');
    config()->set('laravel-multipay.polar.webhook_secret', 'polar_whs_test');
    config()->set('laravel-multipay.polar.product_cache.enabled', true);

    Cache::flush();

    $this->payment = createPayment();
    $this->payment->update(['payment_processor_name' => 'polar']);
    $this->payment->refresh();
});

function polarCheckoutResponse(string $checkoutId = 'checkout-uuid-1', string $url = 'https://sandbox.polar.sh/checkout/checkout-uuid-1'): array
{
    return [
        'id' => $checkoutId,
        'status' => 'open',
        'url' => $url,
        'amount' => 50000,
        'total_amount' => 50000,
        'currency' => 'usd',
        'product_id' => 'prod_1',
    ];
}

function polarWebhookRequest(array $payload, string $secret = 'polar_whs_test', string $webhookId = 'msg_1', ?string $timestamp = null, ?string $signatureOverride = null, bool $omitSignature = false): Request
{
    $timestamp ??= (string) time();
    $body = json_encode($payload);
    $key = base64_encode($secret);
    $signature = $signatureOverride ?? base64_encode(hash_hmac('sha256', "{$webhookId}.{$timestamp}.{$body}", $key, true));

    $headers = [
        'HTTP_WEBHOOK_ID' => $webhookId,
        'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ];

    if (!$omitSignature) {
        $headers['HTTP_WEBHOOK_SIGNATURE'] = "v1,{$signature}";
    }

    return Request::create('/payment/completed/notify', 'POST', [], [], [], $headers, $body);
}

it('creates a checkout and redirects to the checkout url', function () {
    $this->payment->update(['metadata' => ['polar_product_id' => 'prod_explicit']]);

    Http::fake([
        'sandbox-api.polar.sh/v1/checkouts/' => Http::response(polarCheckoutResponse()),
    ]);

    $response = (new Polar())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    expect($response->getTargetUrl())->toBe('https://sandbox.polar.sh/checkout/checkout-uuid-1');

    $this->payment->refresh();
    expect($this->payment->processor_transaction_reference)->toBe('checkout-uuid-1')
        ->and($this->payment->metadata['polar_checkout_url'])->toBe('https://sandbox.polar.sh/checkout/checkout-uuid-1');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/checkouts/')
            && $request['products'] === ['prod_explicit']
            && $request['customer_email'] === 'user@gmail.com'
            && $request['metadata']['transaction_reference'] === $this->payment->transaction_reference
            && str_contains($request['success_url'], 'checkout_id={CHECKOUT_ID}')
            && !array_key_exists('organization_id', $request->data());
    });
});

it('skips product creation when an explicit polar_product_id is supplied', function () {
    $this->payment->update(['metadata' => ['polar_product_id' => 'prod_explicit']]);

    Http::fake([
        'sandbox-api.polar.sh/v1/checkouts/' => Http::response(polarCheckoutResponse()),
    ]);

    (new Polar())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/products'));
});

it('finds-or-creates a fixed product and sends the amount as integer cents', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/products*' => Http::sequence()
            ->push(['items' => []])
            ->push(['id' => 'prod_created']),
        'sandbox-api.polar.sh/v1/checkouts/' => Http::response(polarCheckoutResponse()),
    ]);

    (new Polar())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/products/')
            && $request['prices'][0]['amount_type'] === 'fixed'
            && $request['prices'][0]['price_amount'] === 50000
            && $request['prices'][0]['price_currency'] === 'usd'
            && $request['metadata']['multipay_signature'] !== null
            && !array_key_exists('organization_id', $request->data());
    });
});

it('reuses an existing product found via the metadata filter and does not create a new one', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/products*' => Http::response(['items' => [['id' => 'prod_existing']]]),
        'sandbox-api.polar.sh/v1/checkouts/' => Http::response(polarCheckoutResponse()),
    ]);

    (new Polar())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/products/'));
});

it('reuses the cached product id and does not create the product twice', function () {
    Http::fake([
        'sandbox-api.polar.sh/v1/products*' => Http::sequence()
            ->push(['items' => []])
            ->push(['id' => 'prod_created']),
        'sandbox-api.polar.sh/v1/checkouts/' => Http::response(polarCheckoutResponse()),
    ]);

    (new Polar())->proceedToPaymentGateway($this->payment, 'https://app.test/done');
    (new Polar())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    $productCreations = 0;
    Http::assertSent(function ($request) use (&$productCreations) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/products/')) {
            $productCreations++;
        }

        return true;
    });

    expect($productCreations)->toBe(1);
});

it('confirms a checkout and marks the payment successful', function () {
    $this->payment->update(['processor_transaction_reference' => 'checkout-uuid-1']);

    Http::fake([
        'sandbox-api.polar.sh/v1/checkouts/checkout-uuid-1' => Http::response([
            'id' => 'checkout-uuid-1',
            'status' => 'succeeded',
            'amount' => 50000,
            'total_amount' => 52500,
            'currency' => 'usd',
            'metadata' => ['transaction_reference' => $this->payment->transaction_reference],
        ]),
    ]);

    $request = Request::create('/payment/completed', 'GET', ['checkout_id' => 'checkout-uuid-1']);

    $payment = (new Polar())->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($request);

    expect($payment)->not->toBeNull()
        ->and((bool) $payment->is_success)->toBeTrue()
        ->and((int) $payment->processor_returned_amount)->toBe(52500);
});

it('returns null when confirming a response that carries no checkout_id', function () {
    $request = Request::create('/payment/completed', 'GET');

    expect((new Polar())->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($request))->toBeNull();
});

it('gives value on a succeeded checkout when requerying', function () {
    $this->payment->update(['metadata' => ['polar_checkout_id' => 'checkout-uuid-1']]);

    Http::fake([
        'sandbox-api.polar.sh/v1/checkouts/checkout-uuid-1' => Http::response([
            'id' => 'checkout-uuid-1',
            'status' => 'confirmed',
            'total_amount' => 50000,
            'currency' => 'usd',
        ]),
    ]);

    $reQuery = (new Polar())->reQuery($this->payment);

    expect((bool) $reQuery->payment->is_success)->toBeTrue();
});

it('keeps an open checkout requeryable when requerying', function () {
    $this->payment->update(['metadata' => ['polar_checkout_id' => 'checkout-uuid-1']]);

    Http::fake([
        'sandbox-api.polar.sh/v1/checkouts/checkout-uuid-1' => Http::response([
            'id' => 'checkout-uuid-1',
            'status' => 'open',
        ]),
    ]);

    $reQuery = (new Polar())->reQuery($this->payment);

    expect($reQuery->payment->is_success)->toBeNull();
});

it('marks an expired checkout as unsuccessful when requerying', function () {
    $this->payment->update(['metadata' => ['polar_checkout_id' => 'checkout-uuid-1']]);

    Http::fake([
        'sandbox-api.polar.sh/v1/checkouts/checkout-uuid-1' => Http::response([
            'id' => 'checkout-uuid-1',
            'status' => 'expired',
        ]),
    ]);

    $reQuery = (new Polar())->reQuery($this->payment);

    expect((bool) $reQuery->payment->is_success)->toBeFalse()
        ->and($reQuery->payment->is_success)->not->toBeNull();
});

it('detects an unsettled payment', function () {
    expect((new Polar())->paymentIsUnsettled($this->payment))->toBeTrue();
});

it('resumes an unsettled payment by redirecting to the stored checkout url', function () {
    $this->payment->update(['metadata' => ['polar_checkout_url' => 'https://sandbox.polar.sh/checkout/resume']]);

    $response = (new Polar())->resumeUnsettledPayment($this->payment->refresh());

    expect($response->getTargetUrl())->toBe('https://sandbox.polar.sh/checkout/resume');
});

it('accepts a webhook with a valid standard-webhooks signature and marks the payment successful', function () {
    $this->payment->update(['processor_transaction_reference' => 'checkout-uuid-1']);

    $request = polarWebhookRequest([
        'type' => 'order.paid',
        'data' => [
            'id' => 'order_1',
            'checkout_id' => 'checkout-uuid-1',
            'status' => 'paid',
            'total_amount' => 52500,
            'currency' => 'usd',
            'metadata' => ['transaction_reference' => $this->payment->transaction_reference],
        ],
    ]);

    $payment = (new Polar())->handleExternalWebhookRequest($request);

    expect((bool) $payment->is_success)->toBeTrue()
        ->and($payment->metadata['polar_order_id'])->toBe('order_1');
});

it('marks the payment refunded on an order.refunded webhook', function () {
    $this->payment->update(['processor_transaction_reference' => 'checkout-uuid-1']);

    $request = polarWebhookRequest([
        'type' => 'order.refunded',
        'data' => [
            'id' => 'order_1',
            'checkout_id' => 'checkout-uuid-1',
            'status' => 'refunded',
            'metadata' => ['transaction_reference' => $this->payment->transaction_reference],
        ],
    ]);

    $payment = (new Polar())->handleExternalWebhookRequest($request);

    expect((bool) $payment->is_success)->toBeFalse();
});

it('rejects a webhook whose signature does not match', function () {
    $request = polarWebhookRequest(
        ['type' => 'order.paid', 'data' => ['metadata' => ['transaction_reference' => $this->payment->transaction_reference]]],
        signatureOverride: 'ZGVhZGJlZWY=',
    );

    (new Polar())->handleExternalWebhookRequest($request);
})->throws(Exception::class, 'signature verification failed');

it('rejects a webhook whose timestamp is stale', function () {
    $request = polarWebhookRequest(
        ['type' => 'order.paid', 'data' => ['metadata' => ['transaction_reference' => $this->payment->transaction_reference]]],
        timestamp: (string) (time() - 1000),
    );

    (new Polar())->handleExternalWebhookRequest($request);
})->throws(Exception::class, 'timestamp is outside the allowed tolerance');

it('passes an unsigned request to the next handler by throwing UnknownWebhookException', function () {
    $request = polarWebhookRequest(
        ['type' => 'order.paid', 'data' => ['metadata' => ['transaction_reference' => $this->payment->transaction_reference]]],
        omitSignature: true,
    );

    (new Polar())->handleExternalWebhookRequest($request);
})->throws(UnknownWebhookException::class);
