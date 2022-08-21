<?php

use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Mockery\Mock;
use Illuminate\Support\Facades\App;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    require_once(__DIR__ . "/../database/factories/PaymentFactory.php");

    $payment = (new \PaymentFactory)->create();

    $payment->processor_transaction_reference = 12345;
    $payment->save();

    $this->payment = $payment;
});

// TODO: write these tests:
// it("ensures that submission of the form at /payments/test url does not fail", function () {});
// it("processes payment webhooks", function () {});

it('calls payment handler for payment re-query', function () {
    App::bind('handler-for-payment', function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->expect(
            reQuery: function ($args) {
                return new Payment;
            },
        );

        return $mock;
    });

    App::make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(new Payment());
});

it('fires success events for re-query of successful payments', function () {
    App::bind('handler-for-payment', function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->expect(
            reQuery: function ($args) {
                return new Payment([
                    'is_success' => true
                ]);
            },
        );

        return $mock;
    });

    Event::fake();
    App::make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(new Payment());

    Event::assertDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
});


it('does not fire success events for re-query of unsuccessful payments', function () {
    App::bind('handler-for-payment', function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(Remita::class);
        $mock->makePartial();

        $mock->expect(
            reQuery: function () {
                return new Payment([
                    'is_success' => false,
                ]);
            },
        );

        return $mock;
    });

    Event::fake();

    App::make(BasePaymentHandler::class)->reQueryUnsuccessfulPayment(new Payment());

    Event::assertNotDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
});
