<?php

namespace Damms005\LaravelMultipay\Webhooks\Contracts;

use Illuminate\Http\Request;

interface WebhookHandler
{
    /**
     * Indicates if the webhook handler should be executed.
     */
    public function isHandlerFor(Request $webhookRequest);
}
