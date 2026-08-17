<?php

namespace Damms005\LaravelMultipay\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever a shim implementation of
 * {@see \Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity}
 * had to cancel the previous subscription and create a replacement (i.e.
 * the provider does not support in-place quantity changes).
 *
 * Consuming applications should listen for this event to migrate any local
 * foreign keys / stored subscription codes from `previousSubscriptionCode`
 * to `newSubscriptionCode`.
 */
class SubscriptionCodeReplaced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $paymentHandlerName,
        public readonly string $previousSubscriptionCode,
        public readonly string $newSubscriptionCode,
        public readonly int $newQuantity,
    ) {}
}
