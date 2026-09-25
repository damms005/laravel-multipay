<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Yabacon\Paystack as PaystackHelper;
use Illuminate\Foundation\Auth\User;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Contracts\ManagesSubscriptions;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Contracts\SupportsSubscriptionQuantity;
use Damms005\LaravelMultipay\Enums\ChargeKind;
use Damms005\LaravelMultipay\Events\SubscriptionCodeReplaced;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Services\PaymentResolver;
use Damms005\LaravelMultipay\ValueObjects\PaystackVerificationResponse;
use Damms005\LaravelMultipay\ValueObjects\SubscriptionQuantityChange;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Webhooks\Paystack\ChargeSuccess;
use Damms005\LaravelMultipay\Webhooks\Paystack\InvoicePaymentFailed;
use Damms005\LaravelMultipay\Webhooks\Paystack\InvoiceUpdate;
use Damms005\LaravelMultipay\Webhooks\Paystack\SubscriptionCreate;
use Damms005\LaravelMultipay\Webhooks\Paystack\SubscriptionDisable;
use Damms005\LaravelMultipay\Webhooks\Paystack\SubscriptionNotRenew;

class Paystack extends BasePaymentHandler implements PaymentHandlerInterface, ManagesSubscriptions, SupportsSubscriptionQuantity
{
    public const DIRECT_DEBIT_CHANNEL = 'bank';

    /**
     * @var list<string>
     */
    public const DEFAULT_RECURRING_CHANNELS = ['card', self::DIRECT_DEBIT_CHANNEL];

    protected $secret_key;

    public function __construct()
    {
        $this->secret_key = config("laravel-multipay.paystack_secret_key");

        if (empty($this->secret_key)) {
            // Paystack is currently the default payment handler (because
            // it is the easiest to setup and get up-and-running for starters/testing). Hence,
            // let the error message be contextualized, so we have a better UX for testers/first-timers
            if ($this->isDefaultPaymentHandler()) {
                throw new \Exception("You set Paystack as your default payment handler, but no Paystack Sk found. Please provide SK for Paystack.");
            }
        }
    }

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true): mixed
    {
        $transaction_reference = $payment->transaction_reference;

        return $this->sendUserToPaymentGateway($redirect_or_callback_url, $this->getPayment($transaction_reference));
    }

    /**
     * This is a get request. (https://developers.paystack.co/docs/paystack-standard#section-4-verify-transaction)
     *
     * @param Request $paymentGatewayServerResponse
     *
     * @return Payment
     */
    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        if (!$paymentGatewayServerResponse->has('reference')) {
            return null;
        }

        return $this->processValueForTransaction($paymentGatewayServerResponse->reference);
    }

    /**
     * For Paystack, this is a get request. (https://developers.paystack.co/docs/paystack-standard#section-4-verify-transaction)
     */
    public function processValueForTransaction(string $paystackReference): ?Payment
    {
        throw_if(empty($paystackReference));

        $verificationResponse = $this->verifyPaystackTransaction($paystackReference);

        // status should be true if there was a successful call
        if (!$verificationResponse->status) {
            throw new \Exception($verificationResponse->message);
        }

        $payment = $this->resolveLocalPayment($paystackReference, $verificationResponse);

        if ('success' === $verificationResponse->data['status']) {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $valueWasAlreadyGiven = (bool) $payment->is_success;

            $this->giveValue($payment->transaction_reference, $verificationResponse);

            $payment->refresh();

            if (!$valueWasAlreadyGiven) {
                $this->processPaymentMetadata($payment);
            }
        } else {
            $payment->update([
                'is_success' => 0,
                'processor_returned_response_description' => $verificationResponse->data['gateway_response'],
            ]);
        }

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?ReQuery
    {
        try {
            $verificationResponse = $this->verifyPaystackTransaction($existingPayment->processor_transaction_reference);
        } catch (\Throwable $th) {
            return new ReQuery($existingPayment, ['error' => $th->getMessage()]);
        }

        // status should be true if there was a successful call
        if (!$verificationResponse->status) {
            throw new \Exception($verificationResponse->message);
        }

        $payment = $this->resolveLocalPayment($existingPayment->processor_transaction_reference, $verificationResponse);

        if ('success' === $verificationResponse->data['status']) {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $this->giveValue($payment->transaction_reference, $verificationResponse);
        } else {
            $canStillBeSuccessful = in_array($verificationResponse->data['status'], ['ongoing', 'pending', 'processing', 'queued']);
            $payment->update([
                'is_success' => $canStillBeSuccessful
                    ? null // so can still be selected for requery
                    : false,
                'processor_returned_response_description' => $verificationResponse->data['gateway_response'],
            ]);
        }

        return new ReQuery(
            payment: $payment,
            responseDetails: (array)$verificationResponse,
            rawPayload: (array)$verificationResponse,
        );
    }

    public function classifyCharge(array $rawPayload): ChargeKind
    {
        $planCode = data_get($rawPayload, 'data.plan.plan_code')
            ?? data_get($rawPayload, 'data.plan_object.plan_code')
            ?? data_get($rawPayload, 'data.plan');

        if (empty($planCode)) {
            return ChargeKind::OneOff;
        }

        $customerCode = data_get($rawPayload, 'data.customer.customer_code');

        $plan = PaymentPlan::query()
            ->where('payment_handler_plan_id', $planCode)
            ->where('payment_handler_fqcn', static::getUniquePaymentHandlerName())
            ->orWhere('payment_handler_fqcn', static::class)
            ->first();

        if (! $plan) {
            return ChargeKind::Initial;
        }

        $subscription = Subscription::query()
            ->where('payment_plan_id', $plan->id)
            ->latest('id')
            ->first();

        if (! $subscription) {
            return ChargeKind::Initial;
        }

        $priorSuccessfulPayment = PaymentResolver::newQuery()
            ->where('user_id', $subscription->user_id)
            ->where('is_success', 1)
            ->where(function ($query) use ($plan) {
                $query->where('metadata->payment_plan_id', $plan->id)
                    ->orWhere('metadata->payment_plan_id', (string) $plan->id);
            })
            ->exists();

        return $priorSuccessfulPayment ? ChargeKind::Renewal : ChargeKind::Initial;
    }

    public function toProviderAmount(Payment $payment): int
    {
        return (int) $payment->original_amount_displayed_to_user * 100;
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::handleExternalWebhookRequest
     */
    public function handleExternalWebhookRequest(Request $request): ?Payment
    {
        $this->verifyWebhookSignature($request);

        $webhookEvents = [
            ChargeSuccess::class,
            SubscriptionCreate::class,
            SubscriptionDisable::class,
            SubscriptionNotRenew::class,
            InvoicePaymentFailed::class,
            InvoiceUpdate::class,
        ];

        foreach ($webhookEvents as $webhookEvent) {
            /** @var WebhookHandler */
            $handler = new $webhookEvent();

            if ($this->canHandleWebhook($handler, $request)) {
                return $handler->handle($request);
            }
        }

        throw new UnknownWebhookException($this);
    }

    protected function canHandleWebhook(WebhookHandler $handler, Request $request): bool
    {
        return $handler->isHandlerFor($request);
    }

    /**
     * Paystack signs every webhook with an HMAC SHA512 digest of the raw request
     * body, keyed with the integration's secret key, sent as `x-paystack-signature`.
     *
     * A missing header means the payload was not addressed to this handler, so it
     * raises UnknownWebhookException and lets the next handler try. A present but
     * incorrect signature means the payload is forged and raises loudly instead.
     *
     * @see https://paystack.com/docs/payments/webhooks/#verify-event-origin
     */
    protected function verifyWebhookSignature(Request $request): void
    {
        $signature = $request->header('x-paystack-signature');

        if (blank($signature)) {
            throw new UnknownWebhookException($this);
        }

        $secretKey = (string) config('laravel-multipay.paystack_secret_key');

        if ($secretKey === '') {
            throw new \Exception('Paystack secret key is not configured. Set PAYSTACK_SECRET_KEY.');
        }

        $expectedSignature = hash_hmac('sha512', $request->getContent(), $secretKey);

        if (! hash_equals($expectedSignature, (string) $signature)) {
            throw new \Exception('Paystack webhook signature verification failed.');
        }
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return "";
    }

    protected function verifyPaystackTransaction($paystackReference): PaystackVerificationResponse
    {
        // Confirm that reference has not already gotten value
        // This would have happened most times if you handle the charge.success event.
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        // (full sample verify response is here: https://developers.paystack.co/docs/verifying-transactions)

        return PaystackVerificationResponse::from(
            $paystack->transaction->verify(['reference' => $paystackReference])
        );
    }

    /**
     * Channels Paystack is able to charge again without the payer present. Only
     * a card authorization and a Nigerian direct debit mandate ("bank") qualify;
     * transfer, USSD and wallet channels leave nothing reusable behind.
     *
     * @return list<string>
     */
    public static function recurringChannels(): array
    {
        $configured = config('laravel-multipay.paystack_recurring_channels') ?? self::DEFAULT_RECURRING_CHANNELS;

        $channels = array_values(array_filter(array_map(
            fn (mixed $channel): string => trim((string) $channel),
            is_array($configured) ? $configured : [$configured],
        )));

        return $channels === [] ? self::DEFAULT_RECURRING_CHANNELS : $channels;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withAdditionalPayload(array $payload, Payment $payment): array
    {
        $additionalPayload = Arr::get($payment->metadata, 'additional_payment_payload');

        return is_array($additionalPayload)
            ? array_merge($payload, $additionalPayload)
            : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function restrictToRecurringChannels(array $payload): array
    {
        $allowedChannels = self::recurringChannels();
        $requestedChannels = $payload['channels'] ?? null;

        $channels = is_array($requestedChannels) && $requestedChannels !== []
            ? array_values(array_intersect($requestedChannels, $allowedChannels))
            : $allowedChannels;

        $payload['channels'] = $channels === [] ? $allowedChannels : $channels;

        if (in_array(self::DIRECT_DEBIT_CHANNEL, $payload['channels'], true)) {
            $payload['metadata'] = array_replace_recursive(
                (array) ($payload['metadata'] ?? []),
                ['custom_filters' => ['recurring' => true]],
            );
        }

        return $payload;
    }

    protected function sendUserToPaymentGateway(string $redirect_or_callback_url, Payment $payment)
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $payload = [
            'email' => $payment->getPayerEmail(),
            'amount' => $this->toProviderAmount($payment),
            'callback_url' => $redirect_or_callback_url,
        ];

        $splitCode = Arr::get($payment->metadata, 'split_code');
        if (boolval(trim($splitCode))) {
            $payload['split_code'] = $splitCode;
        }

        $channels = Arr::get($payment->metadata, 'channels');
        if ($channels) {
            $payload['channels'] = $channels;
        }

        $payload = $this->withAdditionalPayload($payload, $payment);

        if ($payment->requiresReusableAuthorization()) {
            $payload = $this->restrictToRecurringChannels($payload);
        }

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        $trx = $paystack->transaction->initialize($payload);

        // status should be true if there was a successful call
        if (!$trx->status) {
            throw new \Exception($trx->message);
        }

        $payment = PaymentResolver::newQuery()
            ->withTrashed()
            ->where('transaction_reference', $payment->transaction_reference)
            ->firstOrFail();

        $metadata = is_null($payment->metadata) ? [] : (array)$payment->metadata;

        $payment->update([
            'processor_transaction_reference' => $trx->data->reference,
            'metadata' => array_merge($metadata, [
                'paystack_authorization_url' => $trx->data->authorization_url
            ]),
        ]);

        // full sample initialize response is here: https://developers.paystack.co/docs/initialize-a-transaction
        // Get the user to click link to start payment or simply redirect to the url generated
        return redirect()->away($trx->data->authorization_url);
    }

    protected function giveValue(string $transactionReference, PaystackVerificationResponse $paystackResponse)
    {
        PaymentResolver::newQuery()
            ->withTrashed()
            ->where('transaction_reference', $transactionReference)
            ->firstOrFail()
            ->update([
                "is_success" => 1,
                "processor_returned_amount" => $paystackResponse->data['amount'],
                "processor_returned_transaction_date" => new Carbon($paystackResponse->data['created_at']),
                'processor_returned_response_description' => $paystackResponse->data['gateway_response'],
            ]);
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        return is_null($payment->is_success);
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        if (!array_key_exists('paystack_authorization_url', (array)$payment->metadata)) {
            throw new \Exception("Attempt was made to resume a Paystack payment that does not have payment URL. Payment id is {$payment->id}");
        }

        return redirect()->away($payment->metadata['paystack_authorization_url']);
    }

    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $response = $paystack->plan->create([
            'name' => $name,
            'amount' => $amount, // in lowest denomination. e.g. kobo
            'interval' => $interval, // hourly, daily, weekly, monthly, quarterly, biannually (every 6 months) and annually
            'description' => $description,
            'currency' => $currency, // Allowed values are NGN, GHS, ZAR or USD
        ]);

        return $response->data->plan_code;
    }

    public function subscribeToPlan(User $user, PaymentPlan $plan, string $transactionReference): string
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $payload = [
            'email' => $user->email,
            'amount' => (int) $plan->amount * 100,
            'plan' => $plan->payment_handler_plan_id,
            'reference' => $transactionReference,
            'callback_url' => route('payment.finished.callback_url'),
        ];

        $payment = PaymentResolver::newQuery()
            ->withTrashed()
            ->where('transaction_reference', $transactionReference)
            ->first();

        if ($payment) {
            $payload = $this->withAdditionalPayload($payload, $payment);
        }

        $trx = $paystack->transaction->initialize($this->restrictToRecurringChannels($payload));

        if (!$trx->status) {
            throw new \Exception($trx->message);
        }

        PaymentResolver::newQuery()
            ->where('transaction_reference', $transactionReference)
            ->update(['processor_transaction_reference' => $trx->data->reference]);

        return $trx->data->authorization_url;
    }

    public function disableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $response = $paystack->subscription->disable([
            'code' => $subscriptionCode,
            'token' => $emailToken,
        ]);

        if (!$response->status) {
            throw new \Exception($response->message);
        }
    }

    public function enableSubscription(string $subscriptionCode, string $emailToken): void
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $response = $paystack->subscription->enable([
            'code' => $subscriptionCode,
            'token' => $emailToken,
        ]);

        if (!$response->status) {
            throw new \Exception($response->message);
        }
    }

    public function supports(string $capability): bool
    {
        return $capability === SupportsSubscriptionQuantity::CAPABILITY;
    }

    /**
     * Paystack subscriptions have no native seat/quantity concept: the amount
     * is fixed on the Plan. To change quantity we (1) fetch the current
     * subscription to reuse its customer + authorization + amount, (2) disable
     * the old subscription, (3) create a new Plan whose amount is
     * (unit_amount * newQuantity), (4) create a fresh subscription on that
     * new plan against the same authorization code, and (5) dispatch
     * {@see SubscriptionCodeReplaced} so the consuming app can migrate its
     * local FK.
     *
     * `$prorationBehavior` is accepted for interface compatibility but Paystack
     * cannot prorate — the new subscription's first charge is a full period.
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

        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $fetch = $paystack->subscription->fetch(['id' => $subscriptionCode]);

        if (!$fetch->status) {
            throw new \Exception($fetch->message);
        }

        $existing = $fetch->data;
        $resolvedEmailToken = $emailToken ?? ($existing->email_token ?? null);

        if (empty($resolvedEmailToken)) {
            throw new \Exception("Paystack requires an email_token to disable subscription {$subscriptionCode}; none was provided or returned by fetch.");
        }

        $customerEmail = $existing->customer->email ?? null;
        $authorizationCode = $existing->authorization->authorization_code ?? null;
        $unitAmountKobo = (int) ($existing->plan->amount ?? 0);
        $planInterval = $existing->plan->interval ?? null;
        $planCurrency = $existing->plan->currency ?? 'NGN';
        $planName = $existing->plan->name ?? 'plan';

        if (empty($customerEmail) || empty($authorizationCode) || $unitAmountKobo <= 0 || empty($planInterval)) {
            throw new \Exception("Paystack subscription {$subscriptionCode} is missing customer/authorization/plan details required to change quantity.");
        }

        $disable = $paystack->subscription->disable([
            'code' => $subscriptionCode,
            'token' => $resolvedEmailToken,
        ]);

        if (!$disable->status) {
            throw new \Exception($disable->message);
        }

        $newPlanAmountKobo = $unitAmountKobo * $newQuantity;
        $newPlanName = "{$planName} x{$newQuantity} " . strtolower(substr(bin2hex(random_bytes(4)), 0, 8));

        $planCreate = $paystack->plan->create([
            'name' => $newPlanName,
            'amount' => $newPlanAmountKobo,
            'interval' => $planInterval,
            'description' => "Quantity-adjusted plan for {$customerEmail} (x{$newQuantity})",
            'currency' => $planCurrency,
        ]);

        if (!$planCreate->status) {
            throw new \Exception($planCreate->message);
        }

        $newPlanCode = $planCreate->data->plan_code;

        $subscribe = $paystack->subscription->create([
            'customer' => $customerEmail,
            'plan' => $newPlanCode,
            'authorization' => $authorizationCode,
        ]);

        if (!$subscribe->status) {
            throw new \Exception($subscribe->message);
        }

        $newSubscriptionCode = $subscribe->data->subscription_code;

        SubscriptionCodeReplaced::dispatch(
            self::getUniquePaymentHandlerName(),
            $subscriptionCode,
            $newSubscriptionCode,
            $newQuantity,
        );

        return new SubscriptionQuantityChange(
            newSubscriptionCode: $newSubscriptionCode,
            effectiveFrom: $subscribe->data->next_payment_date ?? null,
            proratedChargeAmount: null,
            replacedPreviousCode: true,
            isAsync: false,
            raw: (array) $subscribe->data,
        );
    }

    public function getSubscriptionDetails(string $subscriptionCode): array
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $response = $paystack->subscription->fetch(['id' => $subscriptionCode]);

        if (!$response->status) {
            throw new \Exception($response->message);
        }

        return [
            'subscription_code' => $response->data->subscription_code,
            'email_token' => $response->data->email_token ?? null,
            'status' => $response->data->status ?? null,
            'next_payment_date' => $response->data->next_payment_date ?? null,
        ];
    }

    protected function processPaymentMetadata(Payment $payment): void
    {
        if (!is_iterable($payment->metadata)) {
            return;
        }

        $isPaymentForSubscription = array_key_exists('payment_plan_id', (array)$payment->metadata);

        if (!$isPaymentForSubscription) {
            return;
        }

        $plan = PaymentPlan::findOrFail($payment->metadata['payment_plan_id']);

        $nextPaymentDate = match ($plan->interval) {
            'monthly' => Carbon::now()->addMonth(),
            'quarterly' => Carbon::now()->addMonths(3),
            'biannually' => Carbon::now()->addMonths(6),
            'yearly', 'annually' => Carbon::now()->addYear(),
            default => throw new \Exception("Unknown interval {$plan->interval}"),
        };

        Subscription::create([
            'user_id' => $payment->user_id,
            'payment_plan_id' => $payment->metadata['payment_plan_id'],
            'next_payment_due_date' => $nextPaymentDate,
        ]);
    }

    protected function resolveLocalPayment(string $paystackReferenceNumber, PaystackVerificationResponse $verificationResponse): Payment
    {
        $isPosTerminalTransaction = is_object($verificationResponse->data['metadata']) &&
            ($verificationResponse->data['metadata']->reference ?? false);

        return PaymentResolver::newQuery()
            ->withTrashed()
            /**
             * normal transactions
             */
            ->where('processor_transaction_reference', $paystackReferenceNumber)

            /**
             * terminal POS transactions
             */
            ->when($isPosTerminalTransaction, function ($query) use ($verificationResponse) {
                return $query->orWhere(
                    'metadata->response->data->metadata->reference',
                    $verificationResponse->data['metadata']->reference,
                );
            })
            ->firstOrFail();
    }
}
