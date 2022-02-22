<?php

namespace Damms005\LaravelCashier\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;

class UnknownWebhookException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, Request $paymentNotificationRequest)
    {
        parent::__construct("[{$paymentHandlerInterface->getUniquePaymentHandlerName()}] cannot handle webhook: {$paymentNotificationRequest->getContent()}");
    }
}
