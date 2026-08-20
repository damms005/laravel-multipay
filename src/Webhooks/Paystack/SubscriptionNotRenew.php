<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Damms005\LaravelMultipay\Events\SubscriptionNonRenewing;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Request;

/**
 * Event name: subscription.not_renew
 * Fired when a subscription is marked to not renew at the end of its current
 * period (the customer disabled auto-renew, but the paid period stays valid).
 * Distinct from {@see SubscriptionDisable} (immediate cancellation) and from
 * {@see InvoiceUpdate} (payment failure putting the subscription in an
 * attention/grace state).
 */
class SubscriptionNotRenew implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('event') === 'subscription.not_renew';
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

        $subscription->update(['status' => Subscription::STATUS_NON_RENEWING]);

        SubscriptionNonRenewing::dispatch($subscription->refresh(), $webhookRequest->all());

        return null;
    }
}
