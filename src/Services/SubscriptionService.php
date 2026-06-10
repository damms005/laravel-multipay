<?php

namespace Damms005\LaravelMultipay\Services;

use Illuminate\Foundation\Auth\User;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Actions\CreateNewPayment;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;

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

        $url = $handler->subscribeToPlan($user, $plan, $transactionReference);

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

        return redirect()->away($url);
    }

    public static function getActiveSubscriptionFor(User $user, PaymentPlan $plan): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->where('payment_plan_id', $plan->id)
            ->where('next_payment_due_date', '>', now())
            ->first();
    }
}
