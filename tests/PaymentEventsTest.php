<?php

use Mockery\Mock;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

beforeEach(function () {
    require_once(__DIR__ . "/../database/factories/PaymentFactory.php");

    $payment = (new \PaymentFactory)->create();

    $payment->processor_transaction_reference = 12345;
    $payment->save();

    $this->payment = $payment;
});

it('fires event for successful payment', function ($paymentProvider) {

    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProvider);

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->makePartial();

    $mock->expect(
        handlerGatewayResponse: function (Request $paymentGatewayServerResponse, string $paymentHandlerName): ?Payment {
            $this->payment->is_success = true;
            return $this->payment;
        },
    );

    App::bind(PaymentService::class, function ($app) use ($mock) {
        return $mock;
    });

    Event::fake();

    $this->post(
        route('payment.finished.callback_url'),
        collect($this->payment->toArray())
            ->put('RRR', 12345)
            ->toArray()

    )
        ->assertSee($this->payment->transaction_reference)
        ->assertSee('was successful')
        ->assertStatus(200);

    Event::assertDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
})
    ->with(BasePaymentHandler::getNamesOfPaymentHandlers());

it('unsuccessful payment does not cause event to be fired', function ($paymentProvider) {

    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProvider);

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->makePartial();

    $mock->expect(
        handlerGatewayResponse: function (Request $paymentGatewayServerResponse, string $paymentHandlerName): ?Payment {
            $this->payment->is_success = false;
            return $this->payment;
        },
    );

    App::bind(PaymentService::class, function ($app) use ($mock) {
        return $mock;
    });

    Event::fake();

    $this->post(
        route('payment.finished.callback_url'),
        collect($this->payment->toArray())
            ->put('RRR', 12345)
            ->toArray()
    )
        ->assertSee($this->payment->transaction_reference)
        ->assertSee('was not successful')
        ->assertStatus(200);

    Event::assertNotDispatched(SuccessfulLaravelMultipayPaymentEvent::class);
})
    ->with(BasePaymentHandler::getNamesOfPaymentHandlers());
