<?php

namespace Damms005\LaravelMultipay\Webhooks\Polar;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;

/**
 * Polar emits `subscription.updated` whenever any property of a subscription
 * changes — including seat-count / product changes via the
 * {@see \Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity}
 * flow. We persist the new `seats` value on the local Subscription so the
 * consuming app can trust its `quantity` column.
 */
class SubscriptionUpdated extends PolarWebhookHandler
{
    protected function eventTypes(): array
    {
        return ['subscription.updated'];
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        $subscriptionCode = $webhookRequest->input('data.id');
        $seats = $webhookRequest->input('data.seats');
        $status = $webhookRequest->input('data.status', Subscription::STATUS_ACTIVE);

        $payment = (new Polar())->upsertLocalSubscriptionFromWebhook($payment, $subscriptionCode, $status);

        if ($subscriptionCode !== null && $seats !== null) {
            Subscription::query()
                ->where('payment_handler_subscription_code', $subscriptionCode)
                ->update(['quantity' => (int) $seats]);
        }

        return $payment;
    }
}
