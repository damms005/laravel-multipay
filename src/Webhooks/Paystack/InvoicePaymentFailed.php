<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Damms005\LaravelMultipay\Events\SubscriptionRenewalFailed;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentResolver;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Request;

/**
 * Event name: invoice.payment_failed
 * Fired when Paystack fails to charge a subscription's card for a renewal.
 * Dispatches {@see SubscriptionRenewalFailed} so the app can notify the
 * customer and enter its own dunning flow.
 *
 * @see https://paystack.com/docs/payments/webhooks/#supported-events
 */
class InvoicePaymentFailed implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('event') === 'invoice.payment_failed';
    }

    public function handle(Request $webhookRequest): ?Payment
    {
        $data = (array) $webhookRequest->input('data', []);

        $subscriptionCode = data_get($data, 'subscription.subscription_code')
            ?? data_get($data, 'subscription_code');

        if (empty($subscriptionCode)) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('payment_handler_subscription_code', $subscriptionCode)
            ->first();

        if (! $subscription) {
            return null;
        }

        $attemptedReference = data_get($data, 'transaction.reference')
            ?? data_get($data, 'reference');

        $payment = null;
        if (! empty($attemptedReference)) {
            $payment = PaymentResolver::newQuery()
                ->where('transaction_reference', $attemptedReference)
                ->first();
        }

        SubscriptionRenewalFailed::dispatch($payment, $subscription, $webhookRequest->all());

        return null;
    }
}
