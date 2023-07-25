<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;

/**
 * Use this exception when a webhook payment notification is received and
 * a payment handler opts to handle it, but ended up failing.
 */
class NonActionableWebhookPaymentException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, string $reason, Request $paymentNotificationRequest)
    {
        parent::__construct(
            "[{$paymentHandlerInterface->getUniquePaymentHandlerName()}] could not create payment. Reason: {$reason}. {$paymentNotificationRequest->getContent()}"
        );
    }
}
