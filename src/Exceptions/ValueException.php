<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Models\Payment;

/**
 * This exception is thrown when request is received to give value
 * for a payment that already has previously been given value.
 */
class ValueException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, string $flutterwaveReference)
    {
        parent::__construct("[{$paymentHandlerInterface->getUniquePaymentHandlerName()}] value has already been given for this payment: transaction reference ({$flutterwaveReference}).");
    }
}
