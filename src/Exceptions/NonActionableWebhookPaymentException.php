<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;

class NonActionableWebhookPaymentException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, string $reason, Request $paymentNotificationRequest)
    {
        parent::__construct(
            "[{$paymentHandlerInterface->getUniquePaymentHandlerName()}] could not create payment. Reason: {$reason}. {$paymentNotificationRequest->getContent()}"
        );
    }
}
