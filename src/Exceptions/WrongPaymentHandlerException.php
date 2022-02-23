<?php

namespace Damms005\LaravelCashier\Exceptions;

use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Models\Payment;
use Exception;

class WrongPaymentHandlerException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, Payment $payment)
    {
        parent::__construct("{$paymentHandlerInterface->getUniquePaymentHandlerName()} cannot handle the provided payment: " . json_encode($payment));
    }
}
