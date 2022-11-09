<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;

class MissingUserException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, string $reason)
    {
        parent::__construct(
            "[{$paymentHandlerInterface->getUniquePaymentHandlerName()}] {$reason}"
        );
    }
}
