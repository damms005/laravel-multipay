<?php

namespace Damms005\LaravelMultipay\Webhooks\Bachs;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Bachs;

/**
 * Event type: customer.subscription.created
 * Fired when a subscription is created on Bachs following a successful subscription checkout.
 * Carries the provider subscription id used to later manage (cancel) the subscription.
 */
class CustomerSubscriptionCreated extends BachsWebhookHandler
{
    protected function eventType(): string
    {
        return 'customer.subscription.created';
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        $subscriptionCode = $webhookRequest->input('data.subscription_id')
            ?? $webhookRequest->input('data.id')
            ?? $webhookRequest->input('data.subscription.id');

        return (new Bachs())->upsertLocalSubscriptionFromWebhook($payment, $subscriptionCode);
    }
}
