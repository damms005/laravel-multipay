<?php

namespace Damms005\LaravelMultipay\Contracts;

use Damms005\LaravelMultipay\Exceptions\UnsupportedOperationException;
use Damms005\LaravelMultipay\ValueObjects\SubscriptionQuantityChange;

/**
 * Capability marker for payment handlers that can change the seat count
 * (line-item quantity) on an already-active subscription.
 *
 * Handlers with a native quantity concept (e.g. Polar via its `seats`
 * parameter) update the existing subscription in place. Handlers without a
 * native quantity concept (e.g. Paystack) implement this by cancelling the
 * current subscription and creating a replacement on a new plan whose unit
 * amount reflects the new quantity, and MUST return
 * `replaced_previous_code=true` and dispatch
 * {@see \Damms005\LaravelMultipay\Events\SubscriptionCodeReplaced} so the
 * consuming application can migrate its foreign key.
 *
 * Any implementor of this interface MUST also implement
 * {@see \Damms005\LaravelMultipay\Contracts\ManagesSubscriptions}.
 *
 * Callers should feature-check with `$handler->supports('subscription_quantity')`
 * before invoking this method; handlers that do not support the capability
 * throw {@see UnsupportedOperationException}.
 */
interface SupportsSubscriptionQuantity
{
    public const CAPABILITY = 'subscription_quantity';

    /**
     * Proration vocabulary is generic across handlers; each implementor maps to
     * the provider-specific value (e.g. Polar maps `create_prorations` to
     * `prorate` and `none` to `next_period`).
     */
    public const PRORATION_CREATE = 'create_prorations';

    public const PRORATION_NONE = 'none';

    /**
     * Change the seat count on an active subscription.
     *
     * @param  string  $subscriptionCode  Provider subscription code (e.g. Paystack `SUB_...`, Polar `sub_...`).
     * @param  int  $newQuantity  New absolute seat count (not a delta). Must be >= 1.
     * @param  ?string  $emailToken  Provider-specific management token; required by Paystack, ignored by Polar/Bachs.
     * @param  string  $prorationBehavior  One of `create_prorations` or `none`.
     *
     * @throws UnsupportedOperationException  when this handler does not support the operation.
     * @throws \Exception  when the provider rejects the request.
     */
    public function changeSubscriptionQuantity(
        string $subscriptionCode,
        int $newQuantity,
        ?string $emailToken = null,
        string $prorationBehavior = self::PRORATION_CREATE,
    ): SubscriptionQuantityChange;
}
