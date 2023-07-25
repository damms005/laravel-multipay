<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;

/**
 * This exception is usually thrown by a payment handler when it receives a webhook
 * that it cannot handle. It will usually cause the request to be passed to the next
 * payment handler in the chain.
 */
class UnknownWebhookException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface)
    {
        parent::__construct("[{$paymentHandlerInterface->getUniquePaymentHandlerName()}] cannot handle webhook");
    }
}
