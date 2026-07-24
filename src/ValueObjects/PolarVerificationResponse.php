<?php

namespace Damms005\LaravelMultipay\ValueObjects;

class PolarVerificationResponse
{
    /**
     * @param  ?array<string, mixed> $raw
     */
    public function __construct(
        public ?string $checkoutId = null,
        public ?string $status = null,
        public ?string $reference = null,
        public ?int $amount = null,
        public ?int $totalAmount = null,
        public ?string $currency = null,
        public ?string $productId = null,
        public ?string $customerId = null,
        public ?array $raw = null,
    ) {}

    /**
     * @param array<string, mixed> $response
     */
    public static function from(array $response): self
    {
        $metadata = $response['metadata'] ?? [];

        return new self(
            checkoutId: $response['id'] ?? null,
            status: $response['status'] ?? null,
            reference: $metadata['transaction_reference'] ?? ($response['reference'] ?? null),
            amount: isset($response['amount']) ? (int) $response['amount'] : null,
            totalAmount: isset($response['total_amount']) ? (int) $response['total_amount'] : null,
            currency: $response['currency'] ?? null,
            productId: $response['product_id'] ?? null,
            customerId: $response['customer_id'] ?? null,
            raw: $response,
        );
    }

    public function isPaid(): bool
    {
        return in_array(strtolower((string) $this->status), ['confirmed', 'succeeded'], true);
    }

    public function isPending(): bool
    {
        return in_array(strtolower((string) $this->status), ['open', 'pending'], true);
    }
}
