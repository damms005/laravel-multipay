<?php

use Mockery\Mock;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

it('can confirm payment and send user to payment gateway', function () {
    $sampleInitialPayment = getSampleInitialPaymentRequest();

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $sampleInitialPayment)
        ->assertSee([$sampleInitialPayment['currency']])
        ->assertSee([$sampleInitialPayment['amount']])
        ->assertSee([$sampleInitialPayment['transaction_description']])
        ->assertStatus(200);

    $payments = Payment::all();

    expect($payments->count())->toEqual(1);

    app()->bind(PaymentHandlerInterface::class, function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(BasePaymentHandler::class);
        $mock->makePartial();

        $mock->expect(
            proceedToPaymentGateway: function ($args) {
                return redirect()->away('nowhere');
            },
        );

        return $mock;
    });

    $payload = collect($payments->first())
        ->merge(['preferred_view' => 'laravel-multipay::test.layout'])
        ->toArray();

    $this->post(route('payment.confirmation.submit'), $payload)
        ->assertRedirect();

    $payment = $payments->first();
    $payment->processor_transaction_reference = 12345;
    $payment->save();

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->makePartial();

    $mock->expect(
        handleGatewayResponse: function (Request $paymentGatewayServerResponse, string $paymentHandlerName) use ($payment): ?Payment {
            $payment->is_success = true;
            return $payment;
        },
    );

    app()->bind(PaymentService::class, function ($app) use ($mock) {
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
