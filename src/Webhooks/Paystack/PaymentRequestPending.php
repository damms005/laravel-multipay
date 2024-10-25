<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;

/**
 * Event name: paymentrequest.pending
 * This is sent when the payment request is successfully created.
 *
 * @see https://paystack.com/docs/terminal/push-payment-requests/#listen-to-notifications
 */
class PaymentRequestPending implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        // TODO: Implement isHandlerFor() method.
        return false;
    }

    public function handle(Request $webhookRequest): Payment
    {
        throw new \Exception('Method not implemented');
    }
}
