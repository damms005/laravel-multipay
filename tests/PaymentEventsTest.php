<?php

use Mockery\Mock;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;

beforeEach(function () {
    require_once(__DIR__ . '/../database/factories/PaymentFactory.php');

    $payment = (new \PaymentFactory())->create();

    $payment->processor_transaction_reference = 12345;
    $payment->save();

    $this->payment = $payment;
});

it('fires event for successful payment', function () {
    $paymentProviderFqcn = Remita::class;

    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProviderFqcn);

    if ($paymentProviderFqcn === Paystack::class) {
        config()->set('laravel-multipay.paystack_secret_key', '12345');
    }

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->makePartial();

    $mock->expect(
        handlerGatewayResponse: function (Request $paymentGatewayServerResponse, string $paymentHandlerName): Payment {
            $this->payment->is_success = true;
            return $this->payment;
        },
    );

    app()->bind(PaymentService::class, function () use ($mock) {
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
});

it('unsuccessful payment does not cause event to be fired', function () {
    $paymentProviderFqcn = Remita::class;

    config()->set('laravel-multipay.default_payment_handler_fqcn', $paymentProviderFqcn);

    if ($paymentProviderFqcn === Paystack::class) {
        config()->set('laravel-multipay.paystack_secret_key', '12345');
    }

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

    app()->bind(PaymentService::class, function ($app) use ($mock) {
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
});
