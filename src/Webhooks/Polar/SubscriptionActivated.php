<?php

namespace Damms005\LaravelMultipay\Webhooks\Polar;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;

/**
 * Event types: subscription.created, subscription.active,
 * subscription.uncanceled, subscription.resumed
 * Create/advance the local Subscription record and mark it active.
 */
class SubscriptionActivated extends PolarWebhookHandler
{
    protected function eventTypes(): array
    {
        return ['subscription.created', 'subscription.active', 'subscription.uncanceled', 'subscription.resumed'];
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        $subscriptionCode = $webhookRequest->input('data.id');

        return (new Polar())->upsertLocalSubscriptionFromWebhook($payment, $subscriptionCode, Subscription::STATUS_ACTIVE);
    }
}
