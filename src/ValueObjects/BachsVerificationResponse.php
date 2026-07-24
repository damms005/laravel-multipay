<?php

namespace Damms005\LaravelMultipay\ValueObjects;

class BachsVerificationResponse
{
    /**
     * @param  ?array<string, mixed> $charge
     * @param  ?array<string, mixed> $raw
     */
    public function __construct(
        public ?string $status = null,
        public ?string $paymentStatus = null,
        public ?string $reference = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $checkoutId = null,
        public ?string $chargeId = null,
        public ?string $paidAt = null,
        public ?array $charge = null,
        public ?array $raw = null,
    ) {}

    /**
     * @param array<string, mixed> $response
     */
    public static function from(array $response): self
    {
        $charge = $response['charge'] ?? null;

        return new self(
            status: $response['status'] ?? null,
            paymentStatus: $response['payment_status'] ?? null,
            reference: $response['reference'] ?? null,
            amount: isset($response['amount']) ? (string) $response['amount'] : ($charge['amount'] ?? null),
            currency: $response['currency'] ?? ($charge['currency'] ?? null),
            checkoutId: $response['checkout_id'] ?? null,
            chargeId: $charge['charge_id'] ?? ($charge['id'] ?? null),
            paidAt: $charge['paid_at'] ?? ($response['paid_at'] ?? null),
            charge: $charge,
            raw: $response,
        );
    }

    public function isPaid(): bool
    {
        $paidValues = ['paid', 'succeeded', 'success', 'complete', 'completed'];

        return in_array(strtolower((string) $this->paymentStatus), $paidValues, true)
            || in_array(strtolower((string) $this->status), $paidValues, true)
            || in_array(strtolower((string) ($this->charge['status'] ?? '')), $paidValues, true);
    }
}
