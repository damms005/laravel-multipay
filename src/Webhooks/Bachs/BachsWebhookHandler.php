<?php

namespace Damms005\LaravelMultipay\Webhooks\Bachs;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Exceptions\PaymentNotFoundException;

abstract class BachsWebhookHandler implements WebhookHandler
{
    abstract protected function eventType(): string;

    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('type') === $this->eventType();
    }

    protected function findPayment(Request $request): Payment
    {
        $reference = $request->input('data.reference');
        $checkoutId = $request->input('data.checkout_id');
        $chargeId = $request->input('data.charge_id');

        $payment = Payment::withTrashed()
            ->where(function ($query) use ($reference, $checkoutId, $chargeId) {
                if ($reference) {
                    $query->orWhere('transaction_reference', $reference);
                }

                if ($checkoutId) {
                    $query->orWhere('processor_transaction_reference', $checkoutId)
                        ->orWhere('metadata->bachs_checkout_id', $checkoutId);
                }

                if ($chargeId) {
                    $query->orWhere('metadata->bachs_charge_id', $chargeId);
                }
            })
            ->first();

        if (!$payment) {
            throw new PaymentNotFoundException(
                $request,
                "Bachs - Payment not found for {$this->eventType()} event. Payload: " . json_encode($request->all()),
            );
        }

        return $payment;
    }
}
