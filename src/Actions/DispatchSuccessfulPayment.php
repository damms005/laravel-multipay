<?php

namespace Damms005\LaravelMultipay\Actions;

use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\Services\PaymentResolver;

class DispatchSuccessfulPayment
{
    public function __invoke(
        Payment $payment,
        array $rawPayload,
        PaymentHandlerInterface $handler,
    ): void {
        $modelClass = PaymentResolver::model();

        $affected = $modelClass::where('id', $payment->getKey())
            ->whereNull('dispatched_at')
            ->update(['dispatched_at' => now()]);

        if ($affected === 0) {
            return;
        }

        /** @var Payment $fresh */
        $fresh = $modelClass::find($payment->getKey());

        $kind = $handler->classifyCharge($rawPayload);

        $subscription = null;
        $subscriptionCode = $fresh->payment_handler_subscription_code ?? null;

        if (! empty($subscriptionCode)) {
            $subscription = Subscription::where('payment_handler_subscription_code', $subscriptionCode)->first();
        }

        event(new SuccessfulLaravelMultipayPaymentEvent(
            payment: $fresh,
            kind: $kind,
            subscription: $subscription,
            rawPayload: $rawPayload,
        ));
    }
}
