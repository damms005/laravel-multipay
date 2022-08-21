<?php

use Mockery\Mock;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Damms005\LaravelMultipay\Models\Payment;
use function PHPUnit\Framework\assertEquals;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;

it('can confirm payment and send user to payment gateway', function () {
    $paymentProviderFqcn = Remita::class;

    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProviderFqcn);

    $sampleInitialPayment = getSampleInitialPaymentRequest();

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $sampleInitialPayment)
        ->assertSee([$sampleInitialPayment['currency']])
        ->assertSee([$sampleInitialPayment['amount']])
        ->assertSee([$sampleInitialPayment['transaction_description']])
        ->assertStatus(200);

    $payments = Payment::all();

    assertEquals($payments->count(), 1);

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

    $this->post(route('payment.confirmation.submit'), $payload)
        ->assertStatus(200)
        ->assertSee('to payment gateway we go!');

    $payment = $payments->first();
    $payment->processor_transaction_reference = 12345;
    $payment->save();

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->makePartial();

    $mock->expect(
        handlerGatewayResponse: function (Request $paymentGatewayServerResponse, string $paymentHandlerName) use ($payment): ?Payment {
            $payment->is_success = true;
            return $payment;
        },
    );

    App::bind(PaymentService::class, function ($app) use ($mock) {
        return $mock;
    });

    $this->post(
        route('payment.finished.callback_url'),
        collect($payload)
            ->put('RRR', 12345)
            ->toArray()
    )
        ->assertSee('was successful')
        ->assertSee($payment->transaction_reference)
        ->assertStatus(200);
});
