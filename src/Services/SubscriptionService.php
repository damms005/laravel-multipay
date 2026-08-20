<?php

namespace Damms005\LaravelMultipay\Services;

use Damms005\LaravelMultipay\Actions\CreateNewPayment;
use Damms005\LaravelMultipay\Contracts\ManagesSubscriptions;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\SubscriptionManagementException;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Services\PaymentResolver;
use Illuminate\Foundation\Auth\User;

class SubscriptionService
{
    public static function createPaymentPlan(PaymentHandlerInterface $handler, string $name, string $amount, string $interval, string $description, string $currency): PaymentPlan
    {
        $planId = $handler->createPaymentPlan($name, $amount, $interval, $description, $currency);

        return PaymentPlan::create([
            'name' => $name,
            'amount' => $amount,
            'interval' => $interval,
            'description' => $description,
            'currency' => $currency,
            'payment_handler_fqcn' => $handler->getUniquePaymentHandlerName(),
            'payment_handler_plan_id' => $planId,
        ]);
    }

    public static function findOrCreatePaymentPlan(PaymentHandlerInterface $handler, string $amount, string $interval, string $description, string $currency): PaymentPlan
    {
        $existing = PaymentPlan::query()
            ->where('payment_handler_fqcn', $handler->getUniquePaymentHandlerName())
            ->where('amount', $amount)
            ->where('interval', $interval)
            ->where('currency', $currency)
            ->first();

        if ($existing) {
            return $existing;
        }

        $name = strtolower(class_basename($handler)) . "-{$interval}-{$amount}-{$currency}";

        return static::createPaymentPlan($handler, $name, $amount, $interval, $description, $currency);
    }

    public static function subscribeToPlan(PaymentHandlerInterface $handler, User $user, PaymentPlan $plan, string $completionUrl, ?string $transactionReference = null, ?array $metadata = null, ?string $displayAmount = null)
    {
        $transactionReference ??= strtoupper(str()->random());

        $paymentMetadata = array_merge($metadata ?? [], ['payment_plan_id' => $plan->id]);

        (new CreateNewPayment())->execute(
            $handler->getUniquePaymentHandlerName(),
            $user->id,
            $completionUrl,
            $transactionReference,
            $plan->currency,
            $plan->description,
            $displayAmount ?? $plan->amount,
            $paymentMetadata
        );

        $url = $handler->subscribeToPlan($user, $plan, $transactionReference);

        return redirect()->away($url);
    }

    public static function getActiveSubscriptionFor(User $user, PaymentPlan $plan): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->where('payment_plan_id', $plan->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where('next_payment_due_date', '>', now())
            ->first();
    }

    /**
     * Store the provider's subscription code and email token on a local
     * subscription. These are required to later pause, cancel, or resume the
     * subscription. They are typically captured from the provider's
     * subscription.create webhook (e.g. Paystack's `data.subscription_code`
     * and `data.email_token`), or retrieved via the handler's
     * getSubscriptionDetails().
     */
    public static function recordProviderSubscriptionData(Subscription $subscription, string $subscriptionCode, string $emailToken): Subscription
    {
        $subscription->update([
            'payment_handler_subscription_code' => $subscriptionCode,
            'payment_handler_email_token' => $emailToken,
        ]);

        return $subscription->refresh();
    }

    /**
     * Pause a subscription: disable it on the provider so it does not renew,
     * while keeping the local record so it can be resumed later.
     */
    public static function pauseSubscription(PaymentHandlerInterface $handler, Subscription $subscription): Subscription
    {
        static::guardManageable($handler, $subscription);

        /** @var ManagesSubscriptions $handler */
        $handler->disableSubscription(
            $subscription->payment_handler_subscription_code,
            $subscription->payment_handler_email_token,
        );

        $subscription->update(['status' => Subscription::STATUS_PAUSED]);

        return $subscription->refresh();
    }

    /**
     * Cancel a subscription: disable it on the provider permanently. At the
     * provider level this is identical to a pause (the renewal is stopped);
     * the difference is intent, tracked by the local status.
     */
    public static function cancelSubscription(PaymentHandlerInterface $handler, Subscription $subscription): Subscription
    {
        static::guardManageable($handler, $subscription);

        /** @var ManagesSubscriptions $handler */
        $handler->disableSubscription(
            $subscription->payment_handler_subscription_code,
            $subscription->payment_handler_email_token,
        );

        $subscription->update(['status' => Subscription::STATUS_CANCELLED]);

        return $subscription->refresh();
    }

    /**
     * Resume a previously paused subscription: re-enable it on the provider so
     * it renews again.
     */
    public static function resumeSubscription(PaymentHandlerInterface $handler, Subscription $subscription): Subscription
    {
        static::guardManageable($handler, $subscription);

        if (!$subscription->isPaused()) {
            throw SubscriptionManagementException::cannotResume($subscription);
        }

        /** @var ManagesSubscriptions $handler */
        $handler->enableSubscription(
            $subscription->payment_handler_subscription_code,
            $subscription->payment_handler_email_token,
        );

        $subscription->update(['status' => Subscription::STATUS_ACTIVE]);

        return $subscription->refresh();
    }

    /**
     * Materialize a Payment row for a subscription renewal charge that
     * arrived via webhook. Subscription renewals carry a NEW reference each
     * cycle, so we cannot look up the row by reference — we create it, keyed
     * idempotently on the reference so repeated webhook deliveries do not
     * insert duplicates.
     */
    public static function saveRenewalPayment(array $rawPayload): Payment
    {
        $data = (array) data_get($rawPayload, 'data', []);
        $reference = data_get($data, 'reference');

        if (empty($reference)) {
            throw new \InvalidArgumentException('Renewal payload is missing data.reference.');
        }

        $planCode = data_get($data, 'plan.plan_code')
            ?? data_get($data, 'plan_object.plan_code');
        $customerCode = data_get($data, 'customer.customer_code');
        $customerEmail = data_get($data, 'customer.email');
        $amountKobo = (int) data_get($data, 'amount', 0);
        $currency = data_get($data, 'currency', 'NGN');

        $plan = null;
        if (! empty($planCode)) {
            $plan = PaymentPlan::query()
                ->where('payment_handler_plan_id', $planCode)
                ->first();
        }

        $subscription = null;
        if ($plan) {
            $subscription = Subscription::query()
                ->where('payment_plan_id', $plan->id)
                ->where(function ($query) use ($customerCode) {
                    $query->where('metadata->customer_code', $customerCode)
                        ->orWhereNotNull('payment_handler_subscription_code');
                })
                ->latest('id')
                ->first();
        }

        $modelClass = PaymentResolver::model();

        $planName = $plan->name ?? ($planCode ?? 'subscription');
        $displayAmount = (int) ($amountKobo / 100);

        return $modelClass::firstOrCreate(
            ['transaction_reference' => $reference],
            [
                'user_id' => $subscription->user_id ?? null,
                'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
                'processor_transaction_reference' => $reference,
                'payment_handler_subscription_code' => $subscription->payment_handler_subscription_code ?? null,
                'transaction_currency' => $currency,
                'transaction_description' => "Subscription renewal: {$planName}",
                'original_amount_displayed_to_user' => $displayAmount,
                'processor_returned_amount' => $amountKobo,
                'is_success' => 1,
                'processor_returned_response_description' => json_encode($rawPayload),
                'metadata' => array_filter([
                    'payment_plan_id' => $plan?->id,
                    'customer_code' => $customerCode,
                    'customer_email' => $customerEmail,
                    'renewal' => true,
                ]),
            ],
        );
    }

    protected static function guardManageable(PaymentHandlerInterface $handler, Subscription $subscription): void
    {
        if (!$handler instanceof ManagesSubscriptions) {
            throw SubscriptionManagementException::handlerDoesNotSupportManagement($handler);
        }

        if (empty($subscription->payment_handler_subscription_code) || empty($subscription->payment_handler_email_token)) {
            throw SubscriptionManagementException::missingProviderCredentials($subscription);
        }
    }
}
