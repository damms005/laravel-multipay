<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;

/**
 * Event name: paymentrequest.success
 * This is also sent to indicate a successful payment for an invoice. It contains the invoice details.
 *
 * @see https://paystack.com/docs/terminal/push-payment-requests/#listen-to-notifications
 */
class PaymentRequestSuccess implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        // TODO: Implement isHandlerFor() method.
        return false;
    }
}
