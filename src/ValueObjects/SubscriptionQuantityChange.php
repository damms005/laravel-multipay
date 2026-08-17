<?php

namespace Damms005\LaravelMultipay\ValueObjects;

/**
 * Result of {@see \Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity::changeSubscriptionQuantity()}.
 *
 * When `is_async` is true the caller MUST wait for a provider webhook to confirm
 * the change before treating the new subscription as authoritative;
 * `new_subscription_code` may be null in that case.
 *
 * When `replaced_previous_code` is true the previous subscription code was
 * cancelled and a NEW code was issued (shim providers such as Paystack).
 * Consumers should migrate any local FK to the new code and listen for the
 * {@see \Damms005\LaravelMultipay\Events\SubscriptionCodeReplaced} event.
 */
final class SubscriptionQuantityChange
{
    /**
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public readonly ?string $newSubscriptionCode,
        public readonly ?string $effectiveFrom,
        public readonly ?string $proratedChargeAmount,
        public readonly bool $replacedPreviousCode,
        public readonly bool $isAsync = false,
        public readonly ?array $raw = null,
    ) {}

    /**
     * @return array{new_subscription_code: ?string, effective_from: ?string, prorated_charge_amount: ?string, replaced_previous_code: bool, is_async: bool}
     */
    public function toArray(): array
    {
        return [
            'new_subscription_code' => $this->newSubscriptionCode,
            'effective_from' => $this->effectiveFrom,
            'prorated_charge_amount' => $this->proratedChargeAmount,
            'replaced_previous_code' => $this->replacedPreviousCode,
            'is_async' => $this->isAsync,
        ];
    }
}
