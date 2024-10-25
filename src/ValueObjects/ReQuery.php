<?php

namespace Damms005\LaravelMultipay\ValueObjects;

use Damms005\LaravelMultipay\Models\Payment;

class ReQuery
{
    public function __construct(
        public Payment $payment,
        public string $responseDescription,
    ) {}
}
