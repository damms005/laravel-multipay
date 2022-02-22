<?php
namespace Damms005\LaravelCashier\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;

class NonActionableWebhookPaymentException extends Exception
{
    public function __construct(PaymentHandlerInterface $paymentHandlerInterface, string $reason, Request $paymentNotificationRequest)
    {
        parent::__construct(
            "[{$paymentHandlerInterface->getUniquePaymentHandlerName()}] could not create payment. Reason: {$reason}. {$paymentNotificationRequest->getContent()}"
        );
    }
}
