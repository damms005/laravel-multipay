<?php

use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelCashier\Services\PaymentService;

use function PHPUnit\Framework\assertTrue;

it('ensures that all payment handlers can be initiate for payment processing', function () {
    config(["laravel-cashier.paystack_secret_key" => "sk_test_91017d4bc25b969584699baa67c751fc2d060639"]);

    collect(BasePaymentHandler::getNamesOfPaymentHandlers())
        ->each(function ($handlerName) {
            $paymentHandler = PaymentService::getPaymentHandlerByName($handlerName);

            assertTrue(is_subclass_of($paymentHandler, BasePaymentHandler::class));
            assertTrue($paymentHandler instanceof PaymentHandlerInterface);
        });
});

// it("ensures that submission of the form at /payments/test url does not fail", function () {});

// it("fires successful payment event", function () {});
