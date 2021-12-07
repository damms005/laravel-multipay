<?php

use function Pest\Laravel\get;
use Illuminate\Support\Facades\Config;
use function PHPUnit\Framework\assertTrue;
use Damms005\LaravelCashier\Events\SuccessfulLaravelCashierPaymentEvent;
use Illuminate\Support\Facades\Event;
use Damms005\LaravelCashier\Services\PaymentService;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Models\Payment;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Http\Request;

// it("fires successful payment event", function () {});

// it("ensures that submission of the form at /payments/test url does not fail", function () {});

it("processes payment notification", function () {
    Config::set("laravel-cashier.paystack_secret_key", 'abc');

    get('/payment/completed/notify')
    ->assertOk();

    Event::fake();

    /**
     * @var mixed
     */
    $mock = mock(BasePaymentHandler::class);
    $mock->shouldAllowMockingProtectedMethods();
    $mock->makePartial();

    $mock->expect(
        useNotificationHandlers: function () {
            return new Payment;
        }
    );

    expect($mock->processPaymentNotification(new Request))->toEqual(BasePaymentHandler::NOTIFICATION_OKAY);

    Event::assertDispatched(SuccessfulLaravelCashierPaymentEvent::class);
});

it('ensures that all payment handlers can be initiate for payment processing', function () {
    config(["laravel-cashier.paystack_secret_key" => "sk_test_91017d4bc25b969584699baa67c751fc2d060639"]);

    collect(BasePaymentHandler::getNamesOfPaymentHandlers())
        ->each(function ($handlerName) {
            $paymentHandler = PaymentService::getPaymentHandlerByName($handlerName);

            assertTrue(is_subclass_of($paymentHandler, BasePaymentHandler::class));
            assertTrue($paymentHandler instanceof PaymentHandlerInterface);
        });
});
