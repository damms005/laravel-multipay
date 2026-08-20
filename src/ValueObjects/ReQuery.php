<?php

namespace Damms005\LaravelMultipay\ValueObjects;

use Damms005\LaravelMultipay\Models\Payment;

class ReQuery
{
    public function __construct(
        public Payment $payment,

        /**
         * An array of arbitrary shape based on the specific payment handler.
         */
        public array $responseDetails,

        /**
         * The raw payload received from the payment handler, kept so that
         * downstream event handlers can classify or replay the charge.
         */
        public ?array $rawPayload = null,
    ) {}
}
