<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;

/**
 * Event name: invoice.payment_failed
 * This is sent when the payment for the invoice failed.
 *
 * @see https://paystack.com/docs/terminal/push-payment-requests/#listen-to-notifications
 */
class InvoicePaymentFailed implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest)
    {
        // TODO: Implement isHandlerFor() method.
        return false;
    }
}
