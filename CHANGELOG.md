# Changelog

All notable changes to `laravel-multipay` will be documented in this file.

## 9.2.0 - 2026-09-05

### Behaviour change
- Paystack webhooks are now verified before they are acted on. `Paystack::handleExternalWebhookRequest()` checks the `x-paystack-signature` header — an HMAC SHA512 digest of the raw request body keyed with `PAYSTACK_SECRET_KEY` — using `hash_equals`. Previously the handler acted on any payload that merely carried a matching `event` name, so the public webhook endpoint accepted forged Paystack events. The renewal branch was the exposed one: it materializes a Payment straight from the payload, whereas the initial-charge branch re-verifies against the Paystack API before granting value.
- A **missing** signature header raises `UnknownWebhookException`, so the dispatch loop hands the request to the next provider's handler. A **mismatched** signature raises a plain exception and fails loudly, because the payload claims to be Paystack's but is not signed with your key — forged, or signed by a different integration. That second case is what catches webhooks still in flight from an old Paystack account after a key swap.
- Anything that posted unsigned payloads to `route('payment.external-webhook-endpoint')` and relied on them being processed as Paystack events will stop working. Real Paystack traffic is unaffected: Paystack signs every webhook it sends.

### Added
- `tests/PaymentHandlers/PaystackWebhookSignatureTest.php` covering forged signatures, a signature from a different integration's key, unsigned payloads, an unconfigured secret, and a correctly signed webhook running through to its event handler. `handleExternalWebhookRequest()` previously had no test coverage at all — the lifecycle tests instantiate the webhook handler classes directly.
- README section documenting webhook origin verification for every provider that supports it.

## 9.1.0 - 2026-08-26

### Added
- Recurring-safe checkout channels. A checkout that has to leave behind a chargeable instrument now only offers the channels the provider can charge again: Paystack `card` + `bank` (with `metadata.custom_filters.recurring`, so the payer picks a Direct Debit bank and a mandate is created), Monnify `CARD`. Applied automatically to every `SubscriptionService::subscribeToPlan()` checkout, and to any payment whose metadata carries the new `Payment::METADATA_REQUIRES_REUSABLE_AUTHORIZATION` key. Without this, a first subscription payment made by transfer, USSD or a wallet channel succeeds and then has nothing to renew from.
- `Payment::requiresReusableAuthorization()` and the `requires_reusable_authorization` metadata key.
- `Paystack::recurringChannels()` plus the `paystack_recurring_channels` config key (env `PAYSTACK_RECURRING_CHANNELS`, default `card,bank`). Requested channels are narrowed to this list, never widened.
- `additional_payment_payload` metadata is now merged into the Paystack initialization payload — the README documented it, the handler never read it.
- `SubscriptionNonRenewing` event and the Paystack `subscription.not_renew` webhook handler, plus `Subscription::STATUS_NON_RENEWING` (landed after the 9.0.0 tag, released here).

## 9.0.0 - 2026-08-20

### Breaking
- `SuccessfulLaravelMultipayPaymentEvent` constructor now takes `(Payment $payment, ChargeKind $kind, ?Subscription $subscription, array $rawPayload)`. Downstream listeners typed against the old single-arg signature must be updated.
- `PaymentHandlerInterface::handleExternalWebhookRequest` and `WebhookHandler::handle` now return `?Payment` so lifecycle-only webhooks (subscription.disable, invoice.update, invoice.payment_failed) can return null.
- `PaymentHandlerInterface` gains two required methods: `classifyCharge(array $rawPayload): ChargeKind` and `toProviderAmount(Payment $payment): int|string`. `BasePaymentHandler` provides sane defaults (`ChargeKind::OneOff`, naira integer).
- Removed `Paystack::convertAmountToValueRequiredByPaystack()` — internal callers now use `toProviderAmount()`.

### Added
- Configurable `payment_model` + `PaymentResolver` service so downstream apps that subclass `Payment` always emit and query their concrete subclass. Base class remains the default; queried through `PaymentResolver::newQuery()` inside the package.
- `ChargeKind` enum (`Initial`, `Renewal`, `OneOff`) classifying every Paystack charge.
- `DispatchSuccessfulPayment` action with DB-level fire-once guard on a new `dispatched_at` column (indexed) — one event per real trigger, even under webhook retries.
- Subscription renewals are now materialized as their own Payment row (new reference per cycle) via `SubscriptionService::saveRenewalPayment()`; `firstOrCreate` on `transaction_reference` for idempotency.
- New Paystack webhook handlers: `SubscriptionCreate`, `SubscriptionDisable`, `InvoicePaymentFailed`, `InvoiceUpdate`.
- New lifecycle events: `SubscriptionCancelled`, `SubscriptionRenewalFailed`, `SubscriptionSuspended`.
- New nullable column `payment_handler_subscription_code` on `payments` (indexed) so renewal payments link back to their subscription.
- `ReQuery` value object gains nullable `rawPayload` for downstream classification.

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
