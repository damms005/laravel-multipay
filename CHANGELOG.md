# Changelog

All notable changes to `laravel-multipay` will be documented in this file.

## 7.4.0 - 2026-07-24

- Add **Bachs** (bachs.io) payment handler: one-off checkout, subscriptions, subscription management, and HMAC-SHA256-signed webhooks. Merchant-of-Record / Tax-Assist aware. Fixed-product create-or-reuse (optional `bachs_product_id`) with configurable product-lookup cache.
- Add **Polar** (polar.sh) payment handler: one-off checkout, subscriptions (incl. resume), subscription management, and Standard-Webhooks-verified webhooks. Merchant-of-Record. Integer-cents amounts, product create-or-reuse via metadata (optional `polar_product_id`) with configurable cache.
- Both handlers implement `PaymentHandlerInterface` + `ManagesSubscriptions` and are registered as first-class providers.

## 1.0.0 - 202X-XX-XX

- initial release
