<?php

namespace Damms005\LaravelMultipay\Webhooks\Polar;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Polar;

/**
 * Event type: order.paid
 * Fired when an order is paid — for a one-off checkout AND for each
 * subscription charge (first payment and renewals).
 */
class OrderPaid extends PolarWebhookHandler
{
    protected function eventTypes(): array
    {
        return ['order.paid'];
    }

    public function handle(Request $webhookRequest): Payment
    {
        $payment = $this->findPayment($webhookRequest);

        return (new Polar())->markPaymentSuccessfulFromWebhook($payment, $webhookRequest);
    }
}
