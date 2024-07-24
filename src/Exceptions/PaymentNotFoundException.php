<?php

namespace Damms005\LaravelMultipay\Exceptions;

class PaymentNotFoundException extends \Exception
{
    public function __construct(
        public \Illuminate\Http\Request $webhookRequest,
        string $message,
    ) {
        parent::__construct($message);
    }
}
