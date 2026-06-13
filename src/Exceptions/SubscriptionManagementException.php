<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;

class SubscriptionManagementException extends Exception
{
    public static function handlerDoesNotSupportManagement(PaymentHandlerInterface $handler): self
    {
        return new self("Payment handler '{$handler->getUniquePaymentHandlerName()}' does not support subscription management (pause/cancel/resume).");
    }

    public static function missingProviderCredentials(Subscription $subscription): self
    {
        return new self("Subscription #{$subscription->id} is missing the provider subscription code and/or email token required to manage it. Capture these from the provider's subscription.create webhook, or via the handler's getSubscriptionDetails(), before managing the subscription.");
    }

    public static function cannotResume(Subscription $subscription): self
    {
        return new self("Subscription #{$subscription->id} cannot be resumed because it is not paused (current status: {$subscription->status}).");
    }
}
