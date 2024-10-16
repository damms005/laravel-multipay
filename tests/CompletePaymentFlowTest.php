<?php

use Mockery\Mock;

use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;

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

        $mock->expects('proceedToPaymentGateway')->andReturnUsing(fn() => redirect()->away('nowhere'));

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

    $mock->expects('handleGatewayResponse')
        ->andReturnUsing(
            function () use ($payment) {
                $payment->update(['is_success' => true]);
                return $payment->fresh();
            }
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

it('can use payment handler specified in the payload metadata', function () {
    $payload1 = getSampleInitialPaymentRequest();

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $payload1)
        ->assertStatus(200);

    $payload2 = [
        ...$payload1,
        'payment_processor' => Paystack::getUniquePaymentHandlerName(),
    ];

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $payload2)
        ->assertStatus(200);

    $payments = Payment::all();

    expect($payments->count())->toEqual(2);
    expect($payments->get(0)->payment_processor_name)->toEqual('Remita');
    expect($payments->get(1)->payment_processor_name)->toEqual('Paystack');
});
