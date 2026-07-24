<?php

namespace Damms005\LaravelMultipay\Webhooks\Polar;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Exceptions\PaymentNotFoundException;

abstract class PolarWebhookHandler implements WebhookHandler
{
    /**
     * @return array<int, string>
     */
    abstract protected function eventTypes(): array;

    public function isHandlerFor(Request $webhookRequest): bool
    {
        return in_array($webhookRequest->input('type'), $this->eventTypes(), true);
    }

    protected function findPayment(Request $request): Payment
    {
        $reference = $request->input('data.metadata.transaction_reference');
        $checkoutId = $request->input('data.checkout_id') ?? $request->input('data.id');
        $subscriptionId = $request->input('data.subscription_id');

        $payment = Payment::withTrashed()
            ->where(function ($query) use ($reference, $checkoutId, $subscriptionId) {
                if ($reference) {
                    $query->orWhere('transaction_reference', $reference)
                        ->orWhere('processor_transaction_reference', $reference);
                }

                if ($checkoutId) {
                    $query->orWhere('processor_transaction_reference', $checkoutId)
                        ->orWhere('metadata->polar_checkout_id', $checkoutId);
                }

                if ($subscriptionId) {
                    $query->orWhere('metadata->polar_subscription_id', $subscriptionId);
                }
            })
            ->first();

        if (!$payment) {
            throw new PaymentNotFoundException(
                $request,
                'Polar - Payment not found for ' . $request->input('type') . ' event. Payload: ' . json_encode($request->all()),
            );
        }

        return $payment;
    }
}
