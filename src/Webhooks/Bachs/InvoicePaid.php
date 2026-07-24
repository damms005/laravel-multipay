<?php

namespace Damms005\LaravelMultipay\Webhooks\Bachs;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Bachs;

/**
 * Event type: invoice.paid
 * Fired when a subscription invoice is paid (first charge and renewals).
 * Used to create/advance the local Subscription record.
 */
class InvoicePaid extends BachsWebhookHandler
{
    protected function eventType(): string
    {
        return 'invoice.paid';
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        $subscriptionCode = $webhookRequest->input('data.subscription_id')
            ?? $webhookRequest->input('data.subscription.id');

        return (new Bachs())->upsertLocalSubscriptionFromWebhook($payment, $subscriptionCode);
    }
}
