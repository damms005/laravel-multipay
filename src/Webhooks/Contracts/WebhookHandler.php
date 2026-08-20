<?php

namespace Damms005\LaravelMultipay\Webhooks\Contracts;

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Http\Request;

interface WebhookHandler
{
    /**
     * Indicates if the webhook handler should be executed.
     */
    public function isHandlerFor(Request $webhookRequest): bool;

    /**
     * Handle the webhook request. Return the related Payment when the event
     * produced one, or null for lifecycle events that only affect a
     * Subscription (e.g. subscription.disable, invoice.update).
     */
    public function handle(Request $webhookRequest): ?Payment;
}
