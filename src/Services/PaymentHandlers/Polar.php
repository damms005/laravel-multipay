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
use Damms005\LaravelMultipay\Webhooks\Polar\OrderPaid;
use Damms005\LaravelMultipay\Webhooks\Polar\OrderRefunded;
use Damms005\LaravelMultipay\Contracts\ManagesSubscriptions;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Webhooks\Polar\SubscriptionActivated;
use Damms005\LaravelMultipay\Webhooks\Polar\SubscriptionDeactivated;
use Damms005\LaravelMultipay\Webhooks\Polar\SubscriptionUpdated;
use Damms005\LaravelMultipay\ValueObjects\PolarVerificationResponse;
use Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity;
use Damms005\LaravelMultipay\ValueObjects\SubscriptionQuantityChange;

class Polar extends BasePaymentHandler implements PaymentHandlerInterface, ManagesSubscriptions, SupportsSubscriptionQuantity
{
    protected string $access_token;

    protected string $base_url;

    public function __construct()
    {
        $this->access_token = (string) config('laravel-multipay.polar.access_token');
        $this->base_url = $this->resolveBaseUrl();

        if (empty($this->access_token) && $this->isDefaultPaymentHandler()) {
            throw new \Exception('You set Polar as your default payment handler, but no Polar access token was found. Please provide the Organization Access Token for Polar.');
        }
    }

    public static function getUniquePaymentHandlerName(): string
    {
        return 'polar';
    }

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForLiveApiNotTest = false): mixed
    {
        $productId = $this->resolveProductId($payment);

        $response = $this->client()->post('checkouts/', [
            'products' => [$productId],
            'customer_email' => $payment->getPayerEmail(),
            'success_url' => $this->appendCheckoutIdPlaceholder($redirect_or_callback_url),
            'metadata' => ['transaction_reference' => $payment->transaction_reference],
            'external_customer_id' => $payment->user_id ? (string) $payment->user_id : null,
        ]);

        $this->throwIfError($response, 'creating a Polar checkout');

        $data = $response->json();
        $checkoutId = $data['id'];
        $checkoutUrl = $data['url'];

        $freshPayment = Payment::withTrashed()
            ->where('transaction_reference', $payment->transaction_reference)
            ->firstOrFail();

        $metadata = is_null($freshPayment->metadata) ? [] : (array) $freshPayment->metadata;

        $freshPayment->update([
            'processor_transaction_reference' => $checkoutId,
            'metadata' => array_merge($metadata, [
                'polar_checkout_id' => $checkoutId,
                'polar_checkout_url' => $checkoutUrl,
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

        $response = $this->client()->get("checkouts/{$checkoutId}");

        $this->throwIfError($response, 'verifying a Polar checkout');

        $verification = PolarVerificationResponse::from($response->json());

        $payment = $this->resolveLocalPayment($checkoutId, $verification);

        if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            return null;
        }

        if ($verification->isPaid()) {
            $this->markPaymentSuccessful(
                $payment->transaction_reference,
                $verification->totalAmount ?? $verification->amount,
                $verification->status,
            );

            $payment->refresh();

            $this->processPaymentMetadata($payment);
        } else {
            $payment->update([
                'is_success' => 0,
                'processor_returned_response_description' => $verification->status,
            ]);
        }

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?ReQuery
    {
        try {
            $checkoutId = $this->resolveCheckoutId($existingPayment);

            throw_if(empty($checkoutId), new \Exception("No Polar checkout id available to requery payment id {$existingPayment->id}"));

            $response = $this->client()->get("checkouts/{$checkoutId}");

            $this->throwIfError($response, 'requerying a Polar checkout');
        } catch (\Throwable $th) {
            return new ReQuery($existingPayment, ['error' => $th->getMessage()]);
        }

        $checkout = $response->json();
        $verification = PolarVerificationResponse::from($checkout);

        $payment = $existingPayment;

        if ($verification->isPaid()) {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $this->markPaymentSuccessful(
                $payment->transaction_reference,
                $verification->totalAmount ?? $verification->amount,
                $verification->status,
            );
        } else {
            $payment->update([
                'is_success' => $verification->isPending()
                    ? null
                    : false,
                'processor_returned_response_description' => $verification->status,
            ]);
        }

        return new ReQuery(
            payment: $payment->refresh(),
            responseDetails: $checkout,
        );
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        return is_null($payment->is_success);
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        $url = Arr::get((array) $payment->metadata, 'polar_checkout_url');

        if (empty($url)) {
            throw new \Exception("Attempt was made to resume a Polar payment that does not have a checkout URL. Payment id is {$payment->id}");
        }

        return redirect()->away($url);
    }

    public function handleExternalWebhookRequest(Request $request): Payment
    {
        $this->verifyWebhookSignature($request);

        $webhookEvents = [
            OrderPaid::class,
            OrderRefunded::class,
            SubscriptionActivated::class,
            SubscriptionDeactivated::class,
            SubscriptionUpdated::class,
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
        $recurring = $this->mapIntervalToRecurring($interval);

        $response = $this->client()->post('products/', [
            'name' => $name,
            'description' => $description,
            'recurring_interval' => $recurring['interval'],
            'recurring_interval_count' => $recurring['count'],
            'prices' => [
                [
                    'amount_type' => 'fixed',
                    'price_amount' => $this->toCents($amount),
                    'price_currency' => strtolower($currency),
                ],
            ],
        ]);

        $this->throwIfError($response, 'creating a Polar subscription plan');

        return $response->json('id');
    }

    public function subscribeToPlan(User $user, PaymentPlan $plan, string $transactionReference): string
    {
        $response = $this->client()->post('checkouts/', [
            'products' => [$plan->payment_handler_plan_id],
            'customer_email' => $user->email,
            'success_url' => $this->appendCheckoutIdPlaceholder(route('payment.finished.callback_url')),
            'metadata' => ['transaction_reference' => $transactionReference],
        ]);

        $this->throwIfError($response, 'subscribing to a Polar plan');

        $data = $response->json();

        Payment::where('transaction_reference', $transactionReference)
            ->update(['processor_transaction_reference' => $data['id'] ?? $transactionReference]);

        return $data['url'];
    }

    /**
     * Cancel a Polar subscription so that it does not renew. Polar keeps the
     * subscription active until the end of the current period.
     */
    public function disableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $response = $this->client()->patch("subscriptions/{$subscriptionCode}", [
            'cancel_at_period_end' => true,
        ]);

        $this->throwIfError($response, 'disabling a Polar subscription');
    }

    /**
     * Resume a Polar subscription that was scheduled to cancel at period end.
     * This only works while the subscription is still active and its current
     * period has not ended; a hard revoke/DELETE on Polar is terminal and
     * cannot be undone this way.
     */
    public function enableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $response = $this->client()->patch("subscriptions/{$subscriptionCode}", [
            'cancel_at_period_end' => false,
        ]);

        $this->throwIfError($response, 'enabling a Polar subscription');
    }

    public function supports(string $capability): bool
    {
        return $capability === SupportsSubscriptionQuantity::CAPABILITY;
    }

    /**
     * Polar has native seat-based billing: PATCH /subscriptions/{id} with a
     * `seats` field updates the seat count in-place, and the subscription code
     * stays the same. Proration values map to Polar's own vocabulary
     * (`prorate` / `next_period`).
     */
    public function changeSubscriptionQuantity(
        string $subscriptionCode,
        int $newQuantity,
        ?string $emailToken = null,
        string $prorationBehavior = SupportsSubscriptionQuantity::PRORATION_CREATE,
    ): SubscriptionQuantityChange {
        if ($newQuantity < 1) {
            throw new \InvalidArgumentException("New subscription quantity must be at least 1, got {$newQuantity}.");
        }

        $response = $this->client()->patch("subscriptions/{$subscriptionCode}", [
            'seats' => $newQuantity,
            'proration_behavior' => $this->mapProrationBehavior($prorationBehavior),
        ]);

        $this->throwIfError($response, 'changing a Polar subscription seat count');

        $data = $response->json();

        return new SubscriptionQuantityChange(
            newSubscriptionCode: $data['id'] ?? $subscriptionCode,
            effectiveFrom: $data['current_period_start'] ?? null,
            proratedChargeAmount: isset($data['prorated_amount']) ? (string) $data['prorated_amount'] : null,
            replacedPreviousCode: false,
            isAsync: false,
            raw: $data,
        );
    }

    protected function mapProrationBehavior(string $behavior): string
    {
        return match ($behavior) {
            SupportsSubscriptionQuantity::PRORATION_CREATE => 'prorate',
            SupportsSubscriptionQuantity::PRORATION_NONE => 'next_period',
            default => throw new \InvalidArgumentException("Unknown proration behavior '{$behavior}'. Use SupportsSubscriptionQuantity::PRORATION_CREATE or PRORATION_NONE."),
        };
    }

    public function getSubscriptionDetails(string $subscriptionCode): array
    {
        $response = $this->client()->get("subscriptions/{$subscriptionCode}");

        $this->throwIfError($response, 'fetching a Polar subscription');

        $data = $response->json();

        return [
            'subscription_code' => $data['id'] ?? $subscriptionCode,
            'email_token' => null,
            'status' => $data['status'] ?? null,
            'next_payment_date' => $data['current_period_end'] ?? null,
        ];
    }

    public function markPaymentSuccessfulFromWebhook(Payment $payment, Request $request): Payment
    {
        $metadata = [...$payment->metadata ?? []];

        $orderId = $request->input('data.id');
        if ($orderId) {
            $metadata['polar_order_id'] = $orderId;
        }

        $subscriptionId = $request->input('data.subscription_id');
        if ($subscriptionId) {
            $metadata['polar_subscription_id'] = $subscriptionId;
        }

        $metadata['events'] = $metadata['events'] ?? [];
        $metadata['events'][$request->input('type')] = $request->all();

        $payment->update([
            'is_success' => 1,
            'processor_returned_amount' => $request->input('data.total_amount') ?? $request->input('data.amount'),
            'processor_returned_transaction_date' => now(),
            'processor_returned_response_description' => $request->input('data.status', 'paid'),
            'metadata' => $metadata,
        ]);

        $payment->refresh();

        $this->processPaymentMetadata($payment);

        return $payment;
    }

    public function markPaymentRefundedFromWebhook(Payment $payment, Request $request): Payment
    {
        $metadata = [...$payment->metadata ?? []];
        $metadata['events'] = $metadata['events'] ?? [];
        $metadata['events'][$request->input('type')] = $request->all();

        $payment->update([
            'is_success' => 0,
            'processor_returned_response_description' => $request->input('data.status', 'refunded'),
            'metadata' => $metadata,
        ]);

        return $payment->refresh();
    }

    public function upsertLocalSubscriptionFromWebhook(Payment $payment, ?string $subscriptionCode, string $status): Payment
    {
        if (!is_iterable($payment->metadata)) {
            return $payment;
        }

        $planId = Arr::get((array) $payment->metadata, 'payment_plan_id');

        if ($subscriptionCode) {
            $metadata = [...$payment->metadata ?? []];
            $metadata['polar_subscription_id'] = $subscriptionCode;
            $payment->update(['metadata' => $metadata]);
            $payment->refresh();
        }

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
            'status' => $status,
        ]);

        return $payment;
    }

    public function updateLocalSubscriptionStatusFromWebhook(Payment $payment, ?string $subscriptionCode, string $status): Payment
    {
        $subscription = Subscription::query()
            ->when($subscriptionCode, fn ($query) => $query->where('payment_handler_subscription_code', $subscriptionCode))
            ->where('user_id', $payment->user_id)
            ->latest()
            ->first();

        $subscription?->update(['status' => $status]);

        return $payment;
    }

    protected function client(): PendingRequest
    {
        return Http::withToken($this->access_token)
            ->baseUrl(rtrim($this->base_url, '/') . '/v1')
            ->acceptJson()
            ->asJson();
    }

    protected function resolveBaseUrl(): string
    {
        $explicit = config('laravel-multipay.polar.base_url');

        if (!empty($explicit)) {
            return $explicit;
        }

        return config('laravel-multipay.polar.server') === 'production'
            ? 'https://api.polar.sh'
            : 'https://sandbox-api.polar.sh';
    }

    protected function resolveProductId(Payment $payment): string
    {
        $explicit = Arr::get((array) $payment->metadata, 'polar_product_id');

        if (!empty($explicit)) {
            return $explicit;
        }

        return $this->findOrCreateFixedProduct(
            $payment->transaction_description,
            $this->toCents($payment->original_amount_displayed_to_user),
            strtolower($payment->transaction_currency),
            $payment->transaction_description,
        );
    }

    protected function findOrCreateFixedProduct(string $name, int $amount, string $currency, ?string $description = null): string
    {
        $cacheEnabled = (bool) config('laravel-multipay.polar.product_cache.enabled', true);
        $signature = $this->productSignature($name, $amount, $currency);
        $cacheKey = "laravel-multipay:polar:product:{$signature}";

        if ($cacheEnabled && ($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $ttl = (int) config('laravel-multipay.polar.product_cache.ttl', 3600);

        $lock = Cache::lock("laravel-multipay:polar:product-lock:{$signature}", 10);

        return $lock->block(10, function () use ($name, $amount, $currency, $description, $signature, $cacheEnabled, $cacheKey, $ttl) {
            if ($cacheEnabled && ($cached = Cache::get($cacheKey))) {
                return $cached;
            }

            $productId = $this->findMatchingFixedProduct($signature)
                ?? $this->createFixedProduct($name, $amount, $currency, $signature, $description);

            if ($cacheEnabled) {
                Cache::put($cacheKey, $productId, $ttl);
            }

            return $productId;
        });
    }

    protected function findMatchingFixedProduct(string $signature): ?string
    {
        $response = $this->client()->get('products/', [
            'metadata' => ['multipay_signature' => $signature],
        ]);

        $this->throwIfError($response, 'listing Polar products');

        $body = $response->json();
        $products = $body['items'] ?? $body['data'] ?? [];

        return $products[0]['id'] ?? null;
    }

    protected function createFixedProduct(string $name, int $amount, string $currency, string $signature, ?string $description = null): string
    {
        $body = [
            'name' => $name,
            'prices' => [
                [
                    'amount_type' => 'fixed',
                    'price_amount' => $amount,
                    'price_currency' => $currency,
                ],
            ],
            'metadata' => ['multipay_signature' => $signature],
        ];

        if (!empty($description)) {
            $body['description'] = $description;
        }

        $response = $this->client()->post('products/', $body);

        $this->throwIfError($response, 'creating a Polar product');

        return $response->json('id');
    }

    protected function productSignature(string $name, int $amount, string $currency): string
    {
        return md5("{$name}|{$amount}|{$currency}");
    }

    protected function resolveLocalPayment(string $checkoutId, PolarVerificationResponse $verification): Payment
    {
        return Payment::withTrashed()
            ->where('processor_transaction_reference', $checkoutId)
            ->when($verification->reference, fn ($query) => $query->orWhere('transaction_reference', $verification->reference))
            ->firstOrFail();
    }

    protected function resolveCheckoutId(Payment $payment): ?string
    {
        return Arr::get((array) $payment->metadata, 'polar_checkout_id')
            ?? $payment->processor_transaction_reference;
    }

    protected function markPaymentSuccessful(string $transactionReference, int|string|null $amount, ?string $description, ?string $date = null): void
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
        $signatureHeader = $request->header('webhook-signature');
        $webhookId = $request->header('webhook-id');
        $timestamp = $request->header('webhook-timestamp');

        if (empty($signatureHeader) || empty($webhookId) || empty($timestamp)) {
            throw new UnknownWebhookException($this);
        }

        $secret = config('laravel-multipay.polar.webhook_secret');

        if (empty($secret)) {
            throw new \Exception('Polar webhook secret is not configured. Set POLAR_WEBHOOK_SECRET.');
        }

        $toleranceSeconds = 300;

        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            throw new \Exception('Polar webhook timestamp is outside the allowed tolerance.');
        }

        $key = base64_encode($secret);
        $signedContent = "{$webhookId}.{$timestamp}.{$request->getContent()}";
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        foreach (explode(' ', $signatureHeader) as $versionedSignature) {
            $parts = explode(',', $versionedSignature, 2);

            if (count($parts) < 2) {
                continue;
            }

            [$version, $signature] = $parts;

            if ($version === 'v1' && hash_equals($expectedSignature, $signature)) {
                return;
            }
        }

        throw new \Exception('Polar webhook signature verification failed.');
    }

    /**
     * @return array{interval: string, count: int}
     */
    protected function mapIntervalToRecurring(string $interval): array
    {
        return match (strtolower($interval)) {
            'daily' => ['interval' => 'day', 'count' => 1],
            'weekly' => ['interval' => 'week', 'count' => 1],
            'monthly' => ['interval' => 'month', 'count' => 1],
            'quarterly' => ['interval' => 'month', 'count' => 3],
            'biannually' => ['interval' => 'month', 'count' => 6],
            'annually', 'yearly' => ['interval' => 'year', 'count' => 1],
            'hourly' => throw new \Exception("Polar does not support hourly billing intervals; the minimum interval is 'day'."),
            default => throw new \Exception("Unknown billing interval '{$interval}' for Polar."),
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

        $this->upsertLocalSubscriptionFromWebhook(
            $payment,
            Arr::get((array) $payment->metadata, 'polar_subscription_id'),
            Subscription::STATUS_ACTIVE,
        );
    }

    protected function toCents(int|float|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    protected function appendCheckoutIdPlaceholder(string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'checkout_id={CHECKOUT_ID}';
    }

    protected function throwIfError($response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json() ?? [];
        $detail = $body['detail'] ?? ($body['error'] ?? $response->body());

        if (is_array($detail)) {
            $detail = json_encode($detail);
        }

        throw new \Exception("Polar error while {$context}: {$detail}");
    }
}
