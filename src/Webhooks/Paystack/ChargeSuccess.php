<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Exceptions\PaymentNotFoundException;

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
        $payment = Payment::withTrashed()->where('transaction_reference', $webhookRequest->input('data.reference'))
            ->orWhere('transaction_reference', $webhookRequest->input('data.metadata.reference'))
            ->first();

        if (!$payment) {
            throw new PaymentNotFoundException($webhookRequest, get_class(app(PaymentHandlerInterface::class)) . ' - Payment not found in Paystack\'s charge.success event. Payload: ' . json_encode($webhookRequest->all()));
        }

        $metadata = [...$payment->metadata ?? []];
        $metadata = Arr::set($metadata, 'events', $metadata['events'] ?? []);
        $metadata['events']['charge.success'] = $webhookRequest->all();

        $payment->update(['metadata' => $metadata]);

        return (new Paystack())->processValueForTransaction($webhookRequest->input('data.reference'));
    }
}
