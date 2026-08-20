<?php

namespace Damms005\LaravelMultipay\Webhooks\Paystack;

use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Enums\ChargeKind;
use Damms005\LaravelMultipay\Exceptions\PaymentNotFoundException;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;
use Damms005\LaravelMultipay\Services\PaymentResolver;
use Damms005\LaravelMultipay\Services\SubscriptionService;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Event name: charge.success
 * This is sent when the customer successfully makes a payment. It contains the transaction, customer, and card details.
 *
 * v9 change: subscription renewals arrive with a NEW reference each cycle, so
 * looking up an existing Payment row would silently fail. When the payload is
 * classified as a renewal we materialize the Payment row idempotently before
 * handing off. Initial + one-off flows keep the original lookup path.
 *
 * @see https://paystack.com/docs/payments/webhooks/#supported-events
 */
class ChargeSuccess implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        return $webhookRequest->input('event') === 'charge.success';
    }

    public function handle(Request $webhookRequest): Payment
    {
        $paystack = new Paystack();
        $kind = $paystack->classifyCharge($webhookRequest->all());

        if ($kind === ChargeKind::Renewal) {
            $payment = SubscriptionService::saveRenewalPayment($webhookRequest->all());

            $this->recordEventOnPayment($payment, $webhookRequest);

            return $payment;
        }

        $paystackReference = $webhookRequest->input('data.reference');
        $appReference = $webhookRequest->input('data.metadata.reference');

        $payment = PaymentResolver::newQuery()
            ->withTrashed()
            ->where('processor_transaction_reference', $paystackReference)
            ->when($appReference, fn ($query) => $query->orWhere('transaction_reference', $appReference))
            ->first();

        if (! $payment) {
            throw new PaymentNotFoundException(
                $webhookRequest,
                get_class(app(PaymentHandlerInterface::class)) . ' - Payment not found in Paystack\'s charge.success event. Payload: ' . json_encode($webhookRequest->all()),
            );
        }

        $this->recordEventOnPayment($payment, $webhookRequest);

        return $paystack->processValueForTransaction($webhookRequest->input('data.reference'));
    }

    protected function recordEventOnPayment(Payment $payment, Request $webhookRequest): void
    {
        $metadata = [...$payment->metadata ?? []];
        $metadata = Arr::set($metadata, 'events', $metadata['events'] ?? []);
        $metadata['events']['charge.success'] = $webhookRequest->all();

        $payment->update(['metadata' => $metadata]);
    }
}
