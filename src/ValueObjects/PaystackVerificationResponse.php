<?php

namespace Damms005\LaravelMultipay\ValueObjects;

class PaystackVerificationResponse
{
    /**
     * Undocumented function
     *
     * @param  ?boolean $status
     * @param  ?string  $message
     * @param  ?array{status: string, amount: int, gateway_response: string, created_at: string, metadata: mixed} $data
     */
    public function __construct(
        public $status = null,
        public $message = null,
        public $data = null,
    ) {}

    public static function from(\stdClass $paystackResponse)
    {
        return new self(
            status: $paystackResponse->status ?? null,
            message: $paystackResponse->message ?? null,
            data: collect($paystackResponse->data ?? null)
                ->only(['status', 'amount', 'gateway_response', 'created_at', 'metadata'])
                ->toArray(),
        );
    }
}
