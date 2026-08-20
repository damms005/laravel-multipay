<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Damms005\LaravelMultipay\Events\SubscriptionCancelled;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Request;

/**
 * Event name: subscription.disable
 * Fired when a subscription is disabled on Paystack (via API, dashboard, or
 * when it reaches its end-date). Marks the local subscription as cancelled
 * and dispatches {@see SubscriptionCancelled}.
 */
class SubscriptionDisable implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('event') === 'subscription.disable';
    }

    public function handle(Request $webhookRequest): ?Payment
    {
        $subscriptionCode = data_get($webhookRequest->input('data', []), 'subscription_code');

        $subscription = Subscription::query()
            ->where('payment_handler_subscription_code', $subscriptionCode)
            ->first();

        if (! $subscription) {
            return null;
        }

        $subscription->update(['status' => Subscription::STATUS_CANCELLED]);

        SubscriptionCancelled::dispatch($subscription->refresh(), $webhookRequest->all());

        return null;
    }
}
