<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;

class UnsupportedOperationException extends Exception
{
    public static function forCapability(PaymentHandlerInterface $handler, string $capability): self
    {
        return new self("Payment handler '{$handler->getUniquePaymentHandlerName()}' does not support the '{$capability}' capability.");
    }

    public static function forProviderReason(PaymentHandlerInterface $handler, string $capability, string $reason): self
    {
        return new self("Payment handler '{$handler->getUniquePaymentHandlerName()}' cannot perform '{$capability}': {$reason}");
    }
}
