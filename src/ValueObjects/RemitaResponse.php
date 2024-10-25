<?php

namespace Damms005\LaravelMultipay\ValueObjects;

class RemitaResponse
{
    public function __construct(
        public string $status,
        public ?string $paymentDate,
        public ?string $amount,
    ) {}

    public static function from(\stdClass $rrrQueryResponse)
    {
        return new self(
            paymentDate: $rrrQueryResponse->paymentDate,
            status: $rrrQueryResponse->status,
            amount: $rrrQueryResponse->amount,
        );
    }
}
