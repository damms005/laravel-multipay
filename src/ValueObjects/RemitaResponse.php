<?php

namespace Damms005\LaravelMultipay\ValueObjects;

class RemitaResponse
{
    /**
     * @param  string|null $status
     * @param  string|null $paymentDate
     * @param  string|null $amount
     */
    public function __construct(
        public $status = null,
        public $paymentDate = null,
        public $amount = null,
    ) {}

    public static function from(\stdClass $rrrQueryResponse)
    {
        return new self(
            paymentDate: $rrrQueryResponse->paymentDate ?? null,
            status: $rrrQueryResponse->status ?? null,
            amount: $rrrQueryResponse->amount ?? null,
        );
    }
}
