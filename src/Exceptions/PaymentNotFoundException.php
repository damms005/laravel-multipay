<?php

namespace Damms005\LaravelMultipay\Exceptions;

use Exception;

class PaymentNotFoundException extends Exception
{
    public function __construct(
        public \Illuminate\Http\Request $webhookRequest,
        ?string $message = null,
    ) {
        $message = $message ?? 'Payment not found in Paystack\'s charge.success event. Payload: ' . json_encode($webhookRequest->all());

        parent::__construct($message);
    }
}
