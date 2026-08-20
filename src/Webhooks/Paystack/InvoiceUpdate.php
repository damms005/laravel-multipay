<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Damms005\LaravelMultipay\Events\SubscriptionSuspended;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Request;

/**
 * Event name: invoice.update
 * Fired when Paystack updates a subscription invoice. When the payload
 * indicates the subscription has entered a grace/attention state ("attention"
 * or "non-renewing"), we dispatch {@see SubscriptionSuspended} so the app can
 * pause access without cancelling outright.
 *
 * @see https://paystack.com/docs/payments/webhooks/#supported-events
 */
class InvoiceUpdate implements WebhookHandler
{
    protected const SUSPENDED_STATUSES = ['attention', 'non-renewing', 'complete', 'suspended'];

    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('event') === 'invoice.update';
    }

    public function handle(Request $webhookRequest): ?Payment
    {
        $data = (array) $webhookRequest->input('data', []);

        $subscriptionCode = data_get($data, 'subscription.subscription_code')
            ?? data_get($data, 'subscription_code');

        $status = strtolower((string) (
            data_get($data, 'subscription.status')
            ?? data_get($data, 'status')
        ));

        if (empty($subscriptionCode) || ! in_array($status, self::SUSPENDED_STATUSES, true)) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('payment_handler_subscription_code', $subscriptionCode)
            ->first();

        if (! $subscription) {
            return null;
        }

        SubscriptionSuspended::dispatch($subscription, $webhookRequest->all());

        return null;
    }
}
