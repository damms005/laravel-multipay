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
     * Handle the webhook request.
     */
    public function handle(Request $webhookRequest): Payment;
}
