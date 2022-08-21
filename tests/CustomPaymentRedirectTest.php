<?php

use Mockery\Mock;

use Illuminate\Support\Facades\App;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

it('can navigate to custom url upon successful completion', function ($paymentProvider) {
    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProvider);

    $sampleInitialPayment = getSampleInitialPaymentRequest();
    $sampleInitialPayment['metadata'] = json_encode([
        'completion_url' => 'https://foo.bar'
    ]);

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $sampleInitialPayment);

    $payments = Payment::all();

    App::bind(BasePaymentHandler::class, function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(BasePaymentHandler::class);
        $mock->makePartial();

        $mock->expect(
            sendTransactionToPaymentGateway: function ($args) {
                return 'to payment gateway we go!';
            },
        );

        return $mock;
    });

    $payload = collect($payments->first())
        ->merge(['preferred_view' => 'laravel-multipay::test.layout'])
        ->toArray();

    $this->post(route('payment.confirmation.submit'), $payload);

    $payment = $payments->first();
    $payment->processor_transaction_reference = 12345;
    $payment->save();

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->makePartial();

    $mock->expect(
        handlerGatewayResponse: function () use ($payment): ?Payment {
            $payment->is_success = true;
            return $payment;
        },
    );

    App::bind(PaymentService::class, function () use ($mock) {
        return $mock;
    });

    $this->post(
        route('payment.finished.callback_url'),
        collect($payload)
            ->put('RRR', 12345)
            ->toArray()
    )
        ->assertRedirect('https://foo.bar');
})
    ->with(BasePaymentHandler::getFQCNsOfPaymentHandlers());

test('when no custom successful completion page, display usual response', function ($paymentProvider) {
    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProvider);

    $sampleInitialPayment = getSampleInitialPaymentRequest();

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $sampleInitialPayment);

    $payments = Payment::all();

    App::bind(BasePaymentHandler::class, function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(BasePaymentHandler::class);
        $mock->makePartial();

        $mock->expect(
            sendTransactionToPaymentGateway: function ($args) {
                return 'to payment gateway we go!';
            },
        );

        return $mock;
    });

    $payload = collect($payments->first())
        ->merge(['preferred_view' => 'laravel-multipay::test.layout'])
        ->toArray();

    $this->post(route('payment.confirmation.submit'), $payload);

    $payment = $payments->first();
    $payment->processor_transaction_reference = 12345;
    $payment->save();

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->makePartial();

    $mock->expect(
        handlerGatewayResponse: function () use ($payment): ?Payment {
            $payment->is_success = true;
            return $payment;
        },
    );

    App::bind(PaymentService::class, function () use ($mock) {
        return $mock;
    });

    $this->post(
        route('payment.finished.callback_url'),
        collect($payload)
            ->put('RRR', 12345)
            ->toArray()
    )
        ->assertLocation('http://localhost');
})
    ->with(BasePaymentHandler::getFQCNsOfPaymentHandlers());
