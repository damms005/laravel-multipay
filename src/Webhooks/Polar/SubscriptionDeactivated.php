<?php

namespace Damms005\LaravelMultipay\Webhooks\Polar;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;

/**
 * Event types: subscription.canceled, subscription.revoked, subscription.paused
 * Update the local Subscription record's status. A paused subscription maps to
 * the paused status; canceled/revoked map to cancelled.
 */
class SubscriptionDeactivated extends PolarWebhookHandler
{
    protected function eventTypes(): array
    {
        return ['subscription.canceled', 'subscription.revoked', 'subscription.paused'];
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        $subscriptionCode = $webhookRequest->input('data.id');

        $status = $webhookRequest->input('type') === 'subscription.paused'
            ? Subscription::STATUS_PAUSED
            : Subscription::STATUS_CANCELLED;

        return (new Polar())->updateLocalSubscriptionStatusFromWebhook($payment, $subscriptionCode, $status);
    }
}
