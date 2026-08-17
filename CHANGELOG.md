# Changelog

All notable changes to `laravel-multipay` will be documented in this file.

## 8.2.0 - 2026-08-17

- Add `SupportsSubscriptionQuantity` capability interface for changing the seat count on an active subscription.
- **Polar** — native implementation via `PATCH /v1/subscriptions/{id}` with the `seats` field; generic proration vocabulary maps to Polar's `prorate` / `next_period`. `subscription.updated` webhook is now handled and persists the new seat count onto the local `Subscription.quantity` column.
- **Paystack** — shim implementation (disable + create-plan-at-new-price + create-subscription on same authorization). Dispatches `SubscriptionCodeReplaced` so consuming apps can migrate their local FK.
- **Bachs** — implements the capability marker but `supports('subscription_quantity')` returns false and `changeSubscriptionQuantity()` throws `UnsupportedOperationException`. Bachs has no quantity-change endpoint and cancellation is irreversible; consumers must charge a fresh checkout for the additional seat.
- New `PaymentHandlerInterface::supports(string $capability): bool` (default `false` on `BasePaymentHandler`) for graceful degradation.
- New `Subscription.quantity` nullable column (additive migration).
- New value object `SubscriptionQuantityChange`, new event `SubscriptionCodeReplaced`, new exception `UnsupportedOperationException`.

## 7.4.0 - 2026-07-24

- Add **Bachs** (bachs.io) payment handler: one-off checkout, subscriptions, subscription management, and HMAC-SHA256-signed webhooks. Merchant-of-Record / Tax-Assist aware. Fixed-product create-or-reuse (optional `bachs_product_id`) with configurable product-lookup cache.
- Add **Polar** (polar.sh) payment handler: one-off checkout, subscriptions (incl. resume), subscription management, and Standard-Webhooks-verified webhooks. Merchant-of-Record. Integer-cents amounts, product create-or-reuse via metadata (optional `polar_product_id`) with configurable cache.
- Both handlers implement `PaymentHandlerInterface` + `ManagesSubscriptions` and are registered as first-class providers.

## 1.0.0 - 202X-XX-XX

- initial release
