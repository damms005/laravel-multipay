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

        $plan = PaymentPlan::create([
            'name' => $name,
            'amount' => $amount,
            'interval' => $interval,
            'description' => $description,
            'currency' => $currency,
            'payment_handler_fqcn' => $handler->getUniquePaymentHandlerName(),
            'payment_handler_plan_id' => $planId,
        ]);

        return $plan;
    }

    public static function subscribeToPlan(PaymentHandlerInterface $handler, User $user, PaymentPlan $plan, string $completionUrl)
    {
        $handler->subscribeToPlan($user, $plan);

        $transactionReference = str()->random();
        $currency = $plan->currency;
        $transactionDescription = $plan->description;
        $originalAmountDisplayedToUser = $plan->amount;

        (new CreateNewPayment())->execute(
            $handler->getUniquePaymentHandlerName(),
            $user->id,
            $completionUrl,
            $transactionReference,
            $currency,
            $transactionDescription,
            $originalAmountDisplayedToUser,
            ['payment_plan_id' => $plan->id]
        );
    }

    public static function hasActiveSubscriptionForPlan(User $user, PaymentPlan $plan): bool
    {
        return Subscription::where('user_id', $user->id)
            ->where('payment_plan_id', $plan->id)
            ->where('next_payment_due_date', '>', now())
            ->exists();
    }
}
