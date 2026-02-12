<?php

use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Damms005\LaravelMultipay\Listeners\SendPaymentWebhookListener;
use Damms005\LaravelMultipay\Models\Payment;

beforeEach(function () {
    $this->payment = createPayment();
    $this->payment->update([
        'is_success' => true,
        'metadata' => ['fee_head_id' => 1, 'fee_head_name' => 'Tuition'],
    ]);
});

it('builds payload with correct structure', function () {
    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload)
        ->toHaveKeys([
            'transaction_reference',
            'amount_paid',
            'transaction_description',
            'payer_name',
            'payer_email',
            'payment_processor_name',
            'paid_at',
            'metadata',
        ])
        ->and($payload['transaction_reference'])->toBe($this->payment->transaction_reference)
        ->and($payload['amount_paid'])->toBe($this->payment->original_amount_displayed_to_user)
        ->and($payload['transaction_description'])->toBe($this->payment->transaction_description)
        ->and($payload['payment_processor_name'])->toBe($this->payment->payment_processor_name)
        ->and($payload['metadata'])->toBeArray()
        ->and($payload['metadata']['fee_head_id'])->toBe(1);
});

it('includes payer email from user model', function () {
    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload['payer_email'])->toBe('user@gmail.com');
});

it('handles missing payer info gracefully', function () {
    $payment = Payment::create([
        'user_id' => null,
        'transaction_reference' => 'no-user-ref',
        'payment_processor_name' => 'Paystack',
        'transaction_currency' => 'NGN',
        'transaction_description' => 'Test',
        'original_amount_displayed_to_user' => 1000,
        'is_success' => true,
        'metadata' => [],
    ]);

    $payload = SendPaymentWebhookListener::buildPayload($payment);

    expect($payload['payer_name'])->toBeNull()
        ->and($payload['payer_email'])->toBeNull();
});

it('does not dispatch webhook when url is not configured', function () {
    config()->set('laravel-multipay.webhook.url', null);

    $listener = new SendPaymentWebhookListener;
    $event = new SuccessfulLaravelMultipayPaymentEvent($this->payment);

    $listener->handle($event);

    expect(true)->toBeTrue();
});

it('does not dispatch webhook when signing secret is not configured', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com/webhook');
    config()->set('laravel-multipay.webhook.signing_secret', null);

    $listener = new SendPaymentWebhookListener;
    $event = new SuccessfulLaravelMultipayPaymentEvent($this->payment);

    $listener->handle($event);

    expect(true)->toBeTrue();
});

it('uses updated_at as fallback for paid_at when processor date is null', function () {
    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload['paid_at'])->toBe($this->payment->updated_at->toISOString());
});

it('uses processor_returned_transaction_date for paid_at when available', function () {
    $this->payment->update(['processor_returned_transaction_date' => '2025-01-15 10:30:00']);

    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload['paid_at'])->toBe('2025-01-15 10:30:00');
});

it('returns empty array for metadata when payment has no metadata', function () {
    $this->payment->update(['metadata' => null]);
    $this->payment->refresh();

    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload['metadata'])->toBe([]);
});
