<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentResolver;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Request;

/**
 * Event name: subscription.create
 * Fired by Paystack when a new subscription is created (either via API or after
 * a successful initial charge on a plan). We reconcile our local Subscription
 * row so subsequent renewal charges resolve correctly. Fires NO event — the
 * paired charge.success handler already dispatches SuccessfulLaravelMultipayPaymentEvent.
 *
 * @see https://paystack.com/docs/payments/webhooks/#supported-events
 */
class SubscriptionCreate implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('event') === 'subscription.create';
    }

    public function handle(Request $webhookRequest): ?Payment
    {
        $data = (array) $webhookRequest->input('data', []);

        $subscriptionCode = data_get($data, 'subscription_code');
        $emailToken = data_get($data, 'email_token');
        $planCode = data_get($data, 'plan.plan_code');
        $customerCode = data_get($data, 'customer.customer_code');
        $customerEmail = data_get($data, 'customer.email');

        $subscription = Subscription::query()
            ->where('payment_handler_subscription_code', $subscriptionCode)
            ->first();

        if (! $subscription) {
            $plan = PaymentPlan::query()
                ->where('payment_handler_plan_id', $planCode)
                ->first();

            if ($plan) {
                $subscription = Subscription::query()
                    ->where('payment_plan_id', $plan->id)
                    ->whereNull('payment_handler_subscription_code')
                    ->latest('id')
                    ->first();
            }
        }

        if ($subscription) {
            $metadata = is_iterable($subscription->metadata)
                ? (array) $subscription->metadata
                : [];

            $subscription->update([
                'payment_handler_subscription_code' => $subscriptionCode,
                'payment_handler_email_token' => $emailToken,
                'status' => Subscription::STATUS_ACTIVE,
                'metadata' => array_merge($metadata, array_filter([
                    'customer_code' => $customerCode,
                    'customer_email' => $customerEmail,
                ])),
            ]);
        }

        return PaymentResolver::newQuery()
            ->where('payment_handler_subscription_code', $subscriptionCode)
            ->latest('id')
            ->first();
    }
}
