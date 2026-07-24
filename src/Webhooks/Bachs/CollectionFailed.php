<?php

namespace Damms005\LaravelMultipay\Webhooks\Bachs;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Bachs;

/**
 * Event type: collection.failed
 * Fired when a one-off collection or a subscription's charge fails.
 */
class CollectionFailed extends BachsWebhookHandler
{
    protected function eventType(): string
    {
        return 'collection.failed';
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        return (new Bachs())->markPaymentFailedFromWebhook($payment, $webhookRequest);
    }
}
