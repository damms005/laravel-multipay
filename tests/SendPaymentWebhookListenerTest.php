<?php

use Damms005\LaravelMultipay\Contracts\WebhookPayloadPackager;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Damms005\LaravelMultipay\Listeners\SendPaymentWebhookListener;
use Damms005\LaravelMultipay\Models\Payment;

beforeEach(function () {
    $this->payment = createPayment();
    $this->payment->update(['is_success' => true]);
});

it('returns null when no packager is configured', function () {
    config()->set('laravel-multipay.webhook.payload_packager', null);

    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload)->toBeNull();
});

it('uses configured packager to build payload', function () {
    $packager = new class implements WebhookPayloadPackager {
        public function getWebhookPayload(Payment $payment): array
        {
            return [
                'ref' => $payment->transaction_reference,
                'amount' => $payment->original_amount_displayed_to_user,
            ];
        }
    };

    app()->instance(get_class($packager), $packager);
    config()->set('laravel-multipay.webhook.payload_packager', get_class($packager));

    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload)
        ->toHaveKeys(['ref', 'amount'])
        ->and($payload['ref'])->toBe($this->payment->transaction_reference)
        ->and($payload['amount'])->toBe($this->payment->original_amount_displayed_to_user);
});

it('returns null when packager class does not exist', function () {
    config()->set('laravel-multipay.webhook.payload_packager', 'App\\NonExistent\\Packager');

    $payload = SendPaymentWebhookListener::buildPayload($this->payment);

    expect($payload)->toBeNull();
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

it('does not dispatch webhook when no packager configured', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com/webhook');
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');
    config()->set('laravel-multipay.webhook.payload_packager', null);

    $listener = new SendPaymentWebhookListener;
    $event = new SuccessfulLaravelMultipayPaymentEvent($this->payment);

    $listener->handle($event);

    expect(true)->toBeTrue();
});
