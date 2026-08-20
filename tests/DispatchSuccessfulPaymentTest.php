<?php

use Damms005\LaravelMultipay\Actions\DispatchSuccessfulPayment;
use Damms005\LaravelMultipay\Enums\ChargeKind;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('laravel-multipay.paystack_secret_key', 'sk_test_x');
});

it('dispatches the successful payment event exactly once even when invoked repeatedly', function () {
    Event::fake();

    $payment = createPayment();
    $payment->update(['is_success' => true]);

    $handler = new Paystack();
    $action = app(DispatchSuccessfulPayment::class);

    $action($payment, ['event' => 'charge.success'], $handler);
    $action($payment, ['event' => 'charge.success'], $handler);
    $action($payment, ['event' => 'charge.success'], $handler);

    Event::assertDispatchedTimes(SuccessfulLaravelMultipayPaymentEvent::class, 1);
});

it('emits the classified charge kind and full raw payload with the event', function () {
    Event::fake();

    $payment = createPayment();
    $payment->update(['is_success' => true]);

    $rawPayload = ['event' => 'charge.success', 'data' => ['reference' => 'ref']];

    app(DispatchSuccessfulPayment::class)($payment, $rawPayload, new Paystack());

    Event::assertDispatched(
        SuccessfulLaravelMultipayPaymentEvent::class,
        fn (SuccessfulLaravelMultipayPaymentEvent $event) => $event->kind === ChargeKind::OneOff
            && $event->rawPayload === $rawPayload,
    );
});
