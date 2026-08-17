<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\PendingRequest;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Webhooks\Bachs\InvoicePaid;
use Damms005\LaravelMultipay\Webhooks\Bachs\CollectionFailed;
use Damms005\LaravelMultipay\Webhooks\Bachs\CollectionSucceeded;
use Damms005\LaravelMultipay\Contracts\ManagesSubscriptions;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Exceptions\UnsupportedOperationException;
use Damms005\LaravelMultipay\ValueObjects\BachsVerificationResponse;
use Damms005\LaravelMultipay\ValueObjects\SubscriptionQuantityChange;
use Damms005\LaravelMultipay\Webhooks\Bachs\CustomerSubscriptionCreated;

class Bachs extends BasePaymentHandler implements PaymentHandlerInterface, ManagesSubscriptions, SupportsSubscriptionQuantity
{
    protected string $secret_key;

    protected string $base_url;

    public function __construct()
    {
        $this->secret_key = (string) config('laravel-multipay.bachs.secret_key');
        $this->base_url = (string) (config('laravel-multipay.bachs.base_url') ?: 'https://sandbox-api.bachs.io');

        if (empty($this->secret_key) && $this->isDefaultPaymentHandler()) {
            throw new \Exception('You set Bachs as your default payment handler, but no Bachs secret key was found. Please provide the secret key for Bachs.');
        }
    }

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForLiveApiNotTest = false): mixed
    {
        $productId = $this->resolveProductId($payment);

        $response = $this->client()->post('checkout-sessions', [
            'product_cart' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'customer' => ['email' => $payment->getPayerEmail()],
            'reference' => $payment->transaction_reference,
            'success_url' => $redirect_or_callback_url,
            'cancel_url' => $redirect_or_callback_url,
        ]);

        $this->throwIfError($response, 'creating a Bachs checkout session');

        $data = $response->json();
        $checkoutId = $data['checkout_id'];
        $checkoutUrl = $data['checkout_url'];

        $freshPayment = Payment::withTrashed()
            ->where('transaction_reference', $payment->transaction_reference)
            ->firstOrFail();

        $metadata = is_null($freshPayment->metadata) ? [] : (array) $freshPayment->metadata;

        $freshPayment->update([
            'processor_transaction_reference' => $checkoutId,
            'metadata' => array_merge($metadata, [
                'bachs_checkout_id' => $checkoutId,
                'bachs_checkout_url' => $checkoutUrl,
            ]),
        ]);

        return redirect()->away($checkoutUrl);
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        if (!$paymentGatewayServerResponse->has('checkout_id')) {
            return null;
        }

        return $this->processValueForCheckoutSession($paymentGatewayServerResponse->input('checkout_id'));
    }

    public function processValueForCheckoutSession(string $checkoutId): ?Payment
    {
        throw_if(empty($checkoutId));

        $response = $this->client()->get("checkout-sessions/{$checkoutId}");

        $this->throwIfError($response, 'verifying a Bachs checkout session');

        $verification = BachsVerificationResponse::from($response->json());

        $payment = $this->resolveLocalPayment($checkoutId, $verification);

        if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            return null;
        }

        if ($verification->chargeId) {
            $metadata = (array) $payment->metadata;
            if (($metadata['bachs_charge_id'] ?? null) !== $verification->chargeId) {
                $payment->update(['metadata' => array_merge($metadata, ['bachs_charge_id' => $verification->chargeId])]);
                $payment->refresh();
            }
        }

        if ($verification->isPaid()) {
            $this->markPaymentSuccessful(
                $payment->transaction_reference,
                $verification->amount,
                $verification->status ?? $verification->paymentStatus,
                $verification->paidAt,
            );

            $payment->refresh();

            $this->processPaymentMetadata($payment);
        } else {
            $payment->update([
                'is_success' => 0,
                'processor_returned_response_description' => $verification->status ?? $verification->paymentStatus,
            ]);
        }

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?ReQuery
    {
        try {
            $chargeId = $this->resolveChargeId($existingPayment);

            throw_if(empty($chargeId), new \Exception("No Bachs charge id available to requery payment id {$existingPayment->id}"));

            $response = $this->client()->get("payments/charges/{$chargeId}");

            $this->throwIfError($response, 'requerying a Bachs charge');
        } catch (\Throwable $th) {
            return new ReQuery($existingPayment, ['error' => $th->getMessage()]);
        }

        $charge = $response->json();
        $status = strtoupper((string) ($charge['status'] ?? ''));

        $payment = $existingPayment;

        if ($status === 'SUCCEEDED') {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $this->markPaymentSuccessful(
                $payment->transaction_reference,
                $charge['amount'] ?? null,
                $charge['status'] ?? 'SUCCEEDED',
                $charge['paid_at'] ?? null,
            );
        } else {
            $canStillBeSuccessful = in_array($status, ['PENDING', 'AWAITING_PAYMENT', 'PROCESSING'], true);

            $payment->update([
                'is_success' => $canStillBeSuccessful
                    ? null
                    : false,
                'processor_returned_response_description' => $charge['status'] ?? null,
            ]);
        }

        return new ReQuery(
            payment: $payment->refresh(),
            responseDetails: $charge,
        );
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        return is_null($payment->is_success);
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        $url = Arr::get((array) $payment->metadata, 'bachs_checkout_url');

        if (empty($url)) {
            throw new \Exception("Attempt was made to resume a Bachs payment that does not have a checkout URL. Payment id is {$payment->id}");
        }

        return redirect()->away($url);
    }

    public function handleExternalWebhookRequest(Request $request): Payment
    {
        $this->verifyWebhookSignature($request);

        $webhookEvents = [
            CollectionSucceeded::class,
            CollectionFailed::class,
            CustomerSubscriptionCreated::class,
            InvoicePaid::class,
        ];

        foreach ($webhookEvents as $webhookEvent) {
            /** @var WebhookHandler $handler */
            $handler = new $webhookEvent();

            if ($handler->isHandlerFor($request)) {
                return $handler->handle($request);
            }
        }

        throw new UnknownWebhookException($this);
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return '';
    }

    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string
    {
        if (strtoupper($currency) !== 'USD') {
            throw new \Exception("Bachs subscriptions are currently USD-card only. A recurring plan cannot be created in {$currency}.");
        }

        $billingCycle = $this->mapIntervalToBillingCycle($interval);

        $response = $this->client()->post('products', [
            'name' => $name,
            'description' => $description,
            'price' => [
                'price_type' => 'fixed',
                'amount' => $this->formatAmount($amount),
                'currency' => $currency,
            ],
            'billing_cycle' => $billingCycle,
        ]);

        $this->throwIfError($response, 'creating a Bachs subscription plan');

        return $response->json('id');
    }

    public function subscribeToPlan(User $user, PaymentPlan $plan, string $transactionReference): string
    {
        $response = $this->client()->post('checkout-sessions', [
            'product_cart' => [
                ['product_id' => $plan->payment_handler_plan_id, 'quantity' => 1],
            ],
            'customer' => ['email' => $user->email],
            'reference' => $transactionReference,
            'success_url' => route('payment.finished.callback_url'),
        ]);

        $this->throwIfError($response, 'subscribing to a Bachs plan');

        $data = $response->json();

        Payment::where('transaction_reference', $transactionReference)
            ->update(['processor_transaction_reference' => $data['checkout_id'] ?? $transactionReference]);

        return $data['checkout_url'];
    }

    public function disableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $response = $this->client()->delete("subscriptions/{$subscriptionCode}", [
            'cancel_at_period_end' => true,
        ]);

        $this->throwIfError($response, 'disabling a Bachs subscription');
    }

    public function enableSubscription(string $subscriptionCode, string $emailToken): void
    {
        throw new \Exception('Bachs does not support resuming a canceled subscription. Cancellation is irreversible on Bachs; create a new subscription instead.');
    }

    public function supports(string $capability): bool
    {
        return false;
    }

    /**
     * Bachs has no public quantity-change endpoint (verified 2026-08 —
     * docs.bachs.io returned 403 during audit; cancellation is documented as
     * irreversible per {@see self::enableSubscription()}). Any in-place seat
     * bump would require a fresh checkout redirect for card capture, which
     * cannot be modelled by the synchronous
     * {@see SupportsSubscriptionQuantity::changeSubscriptionQuantity()}
     * contract. We therefore expose the capability marker so consumers can
     * `instanceof`-check the whole family, but `supports()` returns false and
     * this method throws {@see UnsupportedOperationException}. Consumers must
     * fall back to a fresh one-off checkout for the extra seat.
     */
    public function changeSubscriptionQuantity(
        string $subscriptionCode,
        int $newQuantity,
        ?string $emailToken = null,
        string $prorationBehavior = SupportsSubscriptionQuantity::PRORATION_CREATE,
    ): SubscriptionQuantityChange {
        throw UnsupportedOperationException::forProviderReason(
            $this,
            SupportsSubscriptionQuantity::CAPABILITY,
            'Bachs does not expose a subscription-quantity endpoint and cancellation is irreversible; use a fresh checkout for the additional seat.',
        );
    }

    public function getSubscriptionDetails(string $subscriptionCode): array
    {
        $response = $this->client()->get("subscriptions/{$subscriptionCode}");

        $this->throwIfError($response, 'fetching a Bachs subscription');

        $data = $response->json();

        return [
            'subscription_code' => $data['id'] ?? $subscriptionCode,
            'email_token' => null,
            'status' => $data['status'] ?? null,
            'next_payment_date' => $data['next_billed_at'] ?? null,
        ];
    }

    public function markPaymentSuccessfulFromWebhook(Payment $payment, Request $request): Payment
    {
        $metadata = [...$payment->metadata ?? []];

        $chargeId = $request->input('data.charge_id');
        if ($chargeId) {
            $metadata['bachs_charge_id'] = $chargeId;
        }

        $metadata['events'] = $metadata['events'] ?? [];
        $metadata['events'][$request->input('type')] = $request->all();

        $payment->update([
            'is_success' => 1,
            'processor_returned_amount' => $request->input('data.amount'),
            'processor_returned_transaction_date' => now(),
            'processor_returned_response_description' => $request->input('data.status', 'succeeded'),
            'metadata' => $metadata,
        ]);

        $payment->refresh();

        $this->processPaymentMetadata($payment);

        return $payment;
    }

    public function markPaymentFailedFromWebhook(Payment $payment, Request $request): Payment
    {
        $metadata = [...$payment->metadata ?? []];
        $metadata['events'] = $metadata['events'] ?? [];
        $metadata['events'][$request->input('type')] = $request->all();

        $payment->update([
            'is_success' => 0,
            'processor_returned_response_description' => $request->input('data.status', 'failed'),
            'metadata' => $metadata,
        ]);

        return $payment->refresh();
    }

    public function upsertLocalSubscriptionFromWebhook(Payment $payment, ?string $subscriptionCode = null): Payment
    {
        if (!is_iterable($payment->metadata)) {
            return $payment;
        }

        $planId = Arr::get((array) $payment->metadata, 'payment_plan_id');

        if (!$planId) {
            return $payment;
        }

        $plan = PaymentPlan::findOrFail($planId);

        $matchAttributes = [
            'user_id' => $payment->user_id,
            'payment_plan_id' => $plan->id,
        ];

        if ($subscriptionCode) {
            $matchAttributes['payment_handler_subscription_code'] = $subscriptionCode;
        }

        Subscription::updateOrCreate($matchAttributes, [
            'next_payment_due_date' => $this->nextPaymentDate($plan),
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        return $payment;
    }

    protected function client(?string $idempotencyKey = null): PendingRequest
    {
        $client = Http::withToken($this->secret_key)
            ->baseUrl(rtrim($this->base_url, '/') . '/v1')
            ->acceptJson()
            ->asJson();

        if ($idempotencyKey) {
            $client = $client->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        return $client;
    }

    protected function resolveProductId(Payment $payment): string
    {
        $explicit = Arr::get((array) $payment->metadata, 'bachs_product_id');

        if (!empty($explicit)) {
            return $explicit;
        }

        return $this->findOrCreateFixedProduct(
            $payment->transaction_description,
            $this->formatAmount($payment->original_amount_displayed_to_user),
            $payment->transaction_currency,
            $payment->transaction_description,
        );
    }

    protected function findOrCreateFixedProduct(string $name, string $amount, string $currency, ?string $description = null): string
    {
        $cacheEnabled = (bool) config('laravel-multipay.bachs.product_cache.enabled', true);
        $signature = $this->productSignature($name, $amount, $currency);
        $cacheKey = "laravel-multipay:bachs:product:{$signature}";

        if ($cacheEnabled && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $ttl = (int) config('laravel-multipay.bachs.product_cache.ttl', 3600);

        $lock = Cache::lock("laravel-multipay:bachs:product-lock:{$signature}", 10);

        return $lock->block(10, function () use ($name, $amount, $currency, $description, $cacheEnabled, $cacheKey, $ttl) {
            if ($cacheEnabled && ($cached = Cache::get($cacheKey))) {
                return $cached;
            }

            $productId = $this->findMatchingFixedProduct($name, $amount, $currency)
                ?? $this->createFixedProduct($name, $amount, $currency, $description);

            if ($cacheEnabled) {
                Cache::put($cacheKey, $productId, $ttl);
            }

            return $productId;
        });
    }

    protected function findMatchingFixedProduct(string $name, string $amount, string $currency): ?string
    {
        $cursor = null;

        do {
            $query = ['limit' => 100];

            if ($cursor) {
                $query['cursor'] = $cursor;
            }

            $response = $this->client()->get('products', $query);

            $this->throwIfError($response, 'listing Bachs products');

            $body = $response->json();
            $products = $body['items'] ?? $body['data'] ?? $body['products'] ?? [];

            foreach ($products as $product) {
                if ($this->productMatchesSignature($product, $name, $amount, $currency)) {
                    return $product['id'];
                }
            }

            $cursor = $body['next_cursor'] ?? Arr::get($body, 'pagination.next_cursor');
        } while (!empty($cursor));

        return null;
    }

    /**
     * @param array<string, mixed> $product
     */
    protected function productMatchesSignature(array $product, string $name, string $amount, string $currency): bool
    {
        $price = $product['price'] ?? [];

        return ($product['name'] ?? null) === $name
            && ($price['price_type'] ?? null) === 'fixed'
            && $this->formatAmount($price['amount'] ?? null) === $amount
            && ($price['currency'] ?? null) === $currency;
    }

    protected function createFixedProduct(string $name, string $amount, string $currency, ?string $description = null): string
    {
        $body = [
            'name' => $name,
            'price' => [
                'price_type' => 'fixed',
                'currency' => $currency,
                'amount' => $amount,
            ],
        ];

        if (!empty($description)) {
            $body['description'] = $description;
        }

        $idempotencyKey = md5("product|{$name}|{$amount}|{$currency}");

        $response = $this->client($idempotencyKey)->post('products', $body);

        $this->throwIfError($response, 'creating a Bachs product');

        return $response->json('id');
    }

    protected function productSignature(string $name, string $amount, string $currency): string
    {
        return md5("{$name}|{$amount}|{$currency}");
    }

    protected function resolveLocalPayment(string $checkoutId, BachsVerificationResponse $verification): Payment
    {
        return Payment::withTrashed()
            ->where('processor_transaction_reference', $checkoutId)
            ->when($verification->reference, fn ($query) => $query->orWhere('transaction_reference', $verification->reference))
            ->firstOrFail();
    }

    protected function resolveChargeId(Payment $payment): ?string
    {
        $chargeId = Arr::get((array) $payment->metadata, 'bachs_charge_id');

        if (!empty($chargeId)) {
            return $chargeId;
        }

        $checkoutId = Arr::get((array) $payment->metadata, 'bachs_checkout_id') ?? $payment->processor_transaction_reference;

        if (empty($checkoutId)) {
            return null;
        }

        $response = $this->client()->get("checkout-sessions/{$checkoutId}");

        if ($response->failed()) {
            return null;
        }

        return BachsVerificationResponse::from($response->json())->chargeId;
    }

    protected function markPaymentSuccessful(string $transactionReference, ?string $amount, ?string $description, ?string $date = null): void
    {
        Payment::withTrashed()->where('transaction_reference', $transactionReference)
            ->firstOrFail()
            ->update([
                'is_success' => 1,
                'processor_returned_amount' => $amount,
                'processor_returned_transaction_date' => $date ? new Carbon($date) : now(),
                'processor_returned_response_description' => $description,
            ]);
    }

    protected function verifyWebhookSignature(Request $request): void
    {
        $signature = $request->header('X-Bachs-Signature');
        $timestamp = $request->header('X-Bachs-Timestamp');

        if (empty($signature) || empty($timestamp)) {
            throw new UnknownWebhookException($this);
        }

        $signingSecret = config('laravel-multipay.bachs.webhook_signing_secret');

        if (empty($signingSecret)) {
            throw new \Exception('Bachs webhook signing secret is not configured. Set BACHS_WEBHOOK_SIGNING_SECRET.');
        }

        $toleranceSeconds = 300;

        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            throw new \Exception('Bachs webhook timestamp is outside the allowed tolerance.');
        }

        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $signingSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \Exception('Bachs webhook signature verification failed.');
        }
    }

    /**
     * @return array{interval: string, frequency: int}
     */
    protected function mapIntervalToBillingCycle(string $interval): array
    {
        return match (strtolower($interval)) {
            'daily' => ['interval' => 'day', 'frequency' => 1],
            'weekly' => ['interval' => 'week', 'frequency' => 1],
            'monthly' => ['interval' => 'month', 'frequency' => 1],
            'quarterly' => ['interval' => 'month', 'frequency' => 3],
            'biannually' => ['interval' => 'month', 'frequency' => 6],
            'annually', 'yearly' => ['interval' => 'year', 'frequency' => 1],
            'hourly' => throw new \Exception("Bachs does not support hourly billing intervals; the minimum interval is 'day'."),
            default => throw new \Exception("Unknown billing interval '{$interval}' for Bachs."),
        };
    }

    protected function nextPaymentDate(PaymentPlan $plan): Carbon
    {
        return match (strtolower($plan->interval)) {
            'daily' => Carbon::now()->addDay(),
            'weekly' => Carbon::now()->addWeek(),
            'monthly' => Carbon::now()->addMonth(),
            'quarterly' => Carbon::now()->addMonths(3),
            'biannually' => Carbon::now()->addMonths(6),
            'annually', 'yearly' => Carbon::now()->addYear(),
            default => throw new \Exception("Unknown interval {$plan->interval}"),
        };
    }

    protected function processPaymentMetadata(Payment $payment): void
    {
        if (!is_iterable($payment->metadata)) {
            return;
        }

        if (!array_key_exists('payment_plan_id', (array) $payment->metadata)) {
            return;
        }

        $this->upsertLocalSubscriptionFromWebhook($payment);
    }

    protected function formatAmount(int | float | string | null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    protected function throwIfError($response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json() ?? [];
        $detail = $body['detail'] ?? $response->body();
        $errorCode = $body['error_code'] ?? null;
        $docUrl = $body['doc_url'] ?? null;

        $message = "Bachs error while {$context}: {$detail}";

        if ($errorCode) {
            $message .= " (error_code: {$errorCode})";
        }

        if ($docUrl) {
            $message .= " See {$docUrl}";
        }

        throw new \Exception($message);
    }
}
