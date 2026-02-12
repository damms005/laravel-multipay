<?php

namespace Damms005\LaravelMultipay\Contracts;

use Damms005\LaravelMultipay\Models\Payment;

interface WebhookPayloadPackager
{
    public function getWebhookPayload(Payment $payment): array;
}
