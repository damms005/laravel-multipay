<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Models\Payment;
use Exception;

class WrongPaymentHandlerException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, Payment $payment)
    {
        parent::__construct("{$paymentHandlerInterface->getUniquePaymentHandlerName()} cannot handle the provided payment: " . json_encode($payment));
    }
}
