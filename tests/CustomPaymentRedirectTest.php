<?php

use Mockery\Mock;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

it('can navigate to custom url upon successful completion', function () {
    $sampleInitialPayment = getSampleInitialPaymentRequest();
    $sampleInitialPayment['metadata'] = json_encode([
        'completion_url' => 'https://foo.bar'
    ]);

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $sampleInitialPayment);

    $payments = Payment::all();

    app()->bind(PaymentHandlerInterface::class, function ($app) {
        return new class () {
            public function proceedToPaymentGateway($args)
            {
                return redirect()->away('nowhere');
            }
        };
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

    $mock->expects('handleGatewayResponse')
        ->andReturnUsing(
            function () use ($payment) {
                $payment->update(['is_success' => true]);
                return $payment->fresh();
            }
        );

    app()->bind(PaymentService::class, fn () => $mock);

    $this->post(
        route('payment.finished.callback_url'),
        collect($payload)
            ->put('RRR', 12345)
            ->toArray()
    )
        ->assertRedirect('https://foo.bar?transaction_reference=' . $payment->transaction_reference);
});

it('redirects to default page when payment completion endpoint is not set', function () {
    $paymentProviderFqcn = Remita::class;

    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProviderFqcn);

    $sampleInitialPayment = getSampleInitialPaymentRequest();

    $this->post(route('payment.show_transaction_details_for_user_confirmation'), $sampleInitialPayment);

    $payment = Payment::first();

    app()->bind(PaymentHandlerInterface::class, function ($app) {
        /**
         * @var Mock<TObject>
         */
        $mock = mock(BasePaymentHandler::class);
        $mock->makePartial();

        $mock->expects('proceedToPaymentGateway')->andReturn(redirect()->away('nowhere'));

        return $mock;
    });

    $payload = collect($payment)
        ->merge(['preferred_view' => 'laravel-multipay::test.layout'])
        ->toArray();

    $this->post(route('payment.confirmation.submit'), $payload);

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

    app()->bind(PaymentService::class, fn () => $mock);

    $this->post(
        route('payment.finished.callback_url'),
        collect($payload)
            ->put('RRR', 12345)
            ->toArray()
    )
        ->assertViewIs('laravel-multipay::transaction-completed');
});
