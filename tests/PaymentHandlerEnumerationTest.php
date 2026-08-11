<?php

use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Flutterwave;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Illuminate\Support\Collection;
use KingFlamez\Rave\Facades\Rave as FlutterwaveRave;

it('enumerates payment handlers without instantiating them, so a missing optional dependency does not fail resolution', function () {
    $handlers = BasePaymentHandler::getNamesOfPaymentHandlers();

    expect($handlers)->toBeInstanceOf(Collection::class)
        ->and($handlers->keys())->toContain(Paystack::class);
});

it('reports a handler as unavailable when its optional package is not installed', function () {
    expect(Paystack::isAvailable())->toBeTrue()
        ->and(Flutterwave::isAvailable())->toBe(class_exists(FlutterwaveRave::class));
});

it('excludes unavailable handlers from the enumeration instead of throwing', function () {
    $keys = BasePaymentHandler::getNamesOfPaymentHandlers()->keys();

    Flutterwave::isAvailable()
        ? expect($keys)->toContain(Flutterwave::class)
        : expect($keys)->not->toContain(Flutterwave::class);
});
