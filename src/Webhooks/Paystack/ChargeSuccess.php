<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;

/**
 * Event name: charge.success
 * This is sent when the customer successfully makes a payment. It contains the transaction, customer, and card details.
 *
 * @see https://paystack.com/docs/terminal/push-payment-requests/#listen-to-notifications
 */
class ChargeSuccess implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('event') === 'charge.success';
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = Payment::where('transaction_reference', $webhookRequest->input('data.reference'))
            ->orWhere('transaction_reference', $webhookRequest->input('data.metadata.reference'))
            ->firstOrFail();

        $metadata = [...$payment->metadata ?? []];
        $metadata = Arr::set($metadata, 'events', $metadata['events'] ?? []);
        $metadata['events']['charge.success'] = $webhookRequest->all();

        $payment->update(['metadata' => $metadata]);

        return (new Paystack())->processValueForTransaction($webhookRequest->input('data.reference'));
    }
}
