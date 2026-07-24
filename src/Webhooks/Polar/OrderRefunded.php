<?php

namespace Damms005\LaravelMultipay\Webhooks\Polar;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;

/**
 * Event type: order.refunded
 * Fired when an order is refunded.
 */
class OrderRefunded extends PolarWebhookHandler
{
    protected function eventTypes(): array
    {
        return ['order.refunded'];
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        return (new Polar())->markPaymentRefundedFromWebhook($payment, $webhookRequest);
    }
}
