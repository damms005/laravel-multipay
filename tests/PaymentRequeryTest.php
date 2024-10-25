<?php

use Mockery\Mock;
use Illuminate\Support\Facades\Event;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;

beforeEach(function () {
    $payment = createPayment();

    $payment->processor_transaction_reference = 12345;
    $payment->save();

    $this->payment = $payment;
});

// TODO: write these tests:
// it("ensures that submission of the form at /payments/test url does not fail", function () {});
// it("processes payment webhooks", function () {});

it('calls payment handler for payment re-query', function () {
    app()->bind(PaymentHandlerInterface::class, function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->expects('reQuery')->andReturn(
            new ReQuery(
                payment: new Payment(),
                responseDescription: 'Successful',
            ),
        );

        return $mock;
    });

    app()->make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(new Payment());
});

it('fires success events for re-query of successful payments', function () {
    app()->bind(PaymentHandlerInterface::class, function () {
        /** @var Mock<TObject> */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->expects('reQuery')->andReturn(
            new ReQuery(
                payment: new Payment(['is_success' => true]),
                responseDescription: 'Successful',
            ),
        );

        return $mock;
    });

    Event::fake();
    app()->make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(new Payment());

    Event::assertDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
});

it('does not fire success events for re-query of unsuccessful payments', function () {
    app()->bind(PaymentHandlerInterface::class, function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->expects('reQuery')->andReturn(
            new ReQuery(
                payment: new Payment(['is_success' => false]),
                responseDescription: 'Went South!',
            ),
        );

        return $mock;
    });

    Event::fake();

    app()->make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(new Payment());

    Event::assertNotDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
});
