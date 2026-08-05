<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Damms005\LaravelMultipay\Contracts\ChargesStoredInstruments;
use Damms005\LaravelMultipay\Contracts\ManagesMandates;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Services\Monnify\MonnifyApiClient;
use Damms005\LaravelMultipay\ValueObjects\DebitResult;
use Damms005\LaravelMultipay\ValueObjects\Mandate;
use Damms005\LaravelMultipay\ValueObjects\MandateRequest;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Webhooks\Monnify\TransactionCompleted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class Monnify extends BasePaymentHandler implements ChargesStoredInstruments, ManagesMandates, PaymentHandlerInterface
{
    protected MonnifyApiClient $client;

    public function __construct(?MonnifyApiClient $client = null)
    {
        parent::__construct();

        $this->client = $client ?? MonnifyApiClient::fromConfig();

        if (! $this->client->hasCredentials() && $this->isDefaultPaymentHandler()) {
            throw new \Exception('You set Monnify as your default payment handler, but no Monnify credentials were found. Please set MONNIFY_API_KEY and MONNIFY_SECRET_KEY.');
        }
    }

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, bool $getFormForLiveApiNotTest = false): mixed
    {
        $body = $this->client->post(MonnifyApiClient::INIT_TRANSACTION_PATH, array_filter([
            'amount' => $this->formatAmount($payment->original_amount_displayed_to_user),
            'customerName' => $this->resolvePayerName($payment),
            'customerEmail' => $payment->getPayerEmail(),
            'paymentReference' => $payment->transaction_reference,
            'paymentDescription' => $payment->transaction_description,
            'currencyCode' => $payment->transaction_currency ?: 'NGN',
            'contractCode' => $this->client->contractCode(),
            'redirectUrl' => $redirect_or_callback_url,
            'paymentMethods' => Arr::get((array) $payment->metadata, 'monnify_payment_methods'),
        ], fn (mixed $value): bool => $value !== null && $value !== ''), 'initialising a Monnify transaction');

        $checkoutUrl = $body['responseBody']['checkoutUrl'] ?? null;
        $transactionReference = $body['responseBody']['transactionReference'] ?? null;

        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new \Exception('Monnify did not return a checkout URL.');
        }

        $freshPayment = Payment::withTrashed()
            ->where('transaction_reference', $payment->transaction_reference)
            ->firstOrFail();

        $metadata = is_null($freshPayment->metadata) ? [] : (array) $freshPayment->metadata;

        $freshPayment->update([
            'processor_transaction_reference' => $transactionReference,
            'metadata' => array_merge($metadata, [
                'monnify_checkout_url' => $checkoutUrl,
                'monnify_transaction_reference' => $transactionReference,
            ]),
        ]);

        return redirect()->away($checkoutUrl);
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        $paymentReference = $paymentGatewayServerResponse->input('paymentReference')
            ?? $paymentGatewayServerResponse->input('paymentreference');

        if (blank($paymentReference)) {
            return null;
        }

        return $this->processValueForTransaction((string) $paymentReference);
    }

    public function processValueForTransaction(string $paymentReference): ?Payment
    {
        $payment = Payment::withTrashed()
            ->where('transaction_reference', $paymentReference)
            ->orWhere('processor_transaction_reference', $paymentReference)
            ->firstOrFail();

        if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            return null;
        }

        $status = $this->fetchTransactionStatus(
            $payment->processor_transaction_reference ?: $paymentReference,
        );

        $this->applyTransactionStatusToPayment($payment, $status);

        return $payment->refresh();
    }

    public function reQuery(Payment $existingPayment): ?ReQuery
    {
        try {
            $status = $this->fetchTransactionStatus(
                $existingPayment->processor_transaction_reference ?: $existingPayment->transaction_reference,
            );
        } catch (\Throwable $throwable) {
            return new ReQuery($existingPayment, ['error' => $throwable->getMessage()]);
        }

        if ($existingPayment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            return null;
        }

        $this->applyTransactionStatusToPayment($existingPayment, $status);

        return new ReQuery(
            payment: $existingPayment->refresh(),
            responseDetails: $status,
        );
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        return is_null($payment->is_success);
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        $url = Arr::get((array) $payment->metadata, 'monnify_checkout_url');

        if (blank($url)) {
            throw new \Exception("Attempt was made to resume a Monnify payment that does not have a checkout URL. Payment id is {$payment->id}");
        }

        return redirect()->away($url);
    }

    public function handleExternalWebhookRequest(Request $request): Payment
    {
        $this->verifyWebhookSignature($request);

        $webhookEvents = [
            TransactionCompleted::class,
        ];

        foreach ($webhookEvents as $webhookEvent) {
            $handler = new $webhookEvent();

            if ($handler->isHandlerFor($request)) {
                return $handler->handle($request);
            }
        }

        throw new UnknownWebhookException($this);
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return (string) $payment->processor_returned_response_description;
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return (string) $responseCode;
    }

    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string
    {
        throw new \Exception('Monnify has no server-side payment plans. Recurring collection on Monnify is driven by a direct debit mandate (ManagesMandates) or a stored card token (ChargesStoredInstruments), with the renewal schedule owned by this application.');
    }

    public function subscribeToPlan(User $user, PaymentPlan $plan, string $transactionReference): string
    {
        throw new \Exception('Monnify has no server-side subscriptions. Create a mandate with createMandate() and debit it on your own schedule, or tokenise a card and charge it with chargeStoredInstrument().');
    }

    public function createMandate(MandateRequest $request): Mandate
    {
        $body = $this->client->post(
            MonnifyApiClient::MANDATE_CREATE_PATH,
            $request->toMonnifyPayload($this->client->contractCode()),
            'creating a Monnify direct debit mandate',
        );

        return Mandate::fromMonnify($body, $request->reference);
    }

    public function getMandateStatus(string $mandateReference): Mandate
    {
        $body = $this->client->get(
            MonnifyApiClient::MANDATE_STATUS_PATH,
            ['mandateReference' => $mandateReference],
            'fetching a Monnify mandate status',
        );

        return Mandate::fromMonnify($body, $mandateReference);
    }

    public function debitMandate(
        string $mandateReference,
        string $amount,
        string $paymentReference,
        string $narration,
    ): DebitResult {
        $body = $this->client->post(MonnifyApiClient::MANDATE_DEBIT_PATH, [
            'mandateReference' => $mandateReference,
            'amount' => $this->formatAmount($amount),
            'reference' => $paymentReference,
            'narration' => $narration,
        ], 'debiting a Monnify mandate');

        return DebitResult::fromMonnify($body);
    }

    public function getDebitStatus(string $paymentReference): DebitResult
    {
        $body = $this->client->get(
            MonnifyApiClient::MANDATE_DEBIT_STATUS_PATH,
            ['reference' => $paymentReference],
            'fetching a Monnify debit status',
        );

        return DebitResult::fromMonnify($body);
    }

    public function cancelMandate(string $mandateReference): Mandate
    {
        $body = $this->client->put(MonnifyApiClient::MANDATE_UPDATE_PATH, [
            'mandateReference' => $mandateReference,
            'mandateStatus' => 'CANCELLED',
        ], 'cancelling a Monnify mandate');

        return Mandate::fromMonnify($body, $mandateReference);
    }

    public function extractStoredInstrumentToken(string $transactionReference): ?string
    {
        $status = $this->fetchTransactionStatus($transactionReference);

        return Arr::get($status, 'responseBody.cardDetails.cardToken') ?: null;
    }

    public function chargeStoredInstrument(
        string $token,
        string $payerEmailAtTokenization,
        string $amount,
        string $paymentReference,
        string $narration,
        string $currency = 'NGN',
    ): DebitResult {
        $body = $this->client->post(MonnifyApiClient::CHARGE_CARD_TOKEN_PATH, [
            'cardToken' => $token,
            'amount' => $this->formatAmount($amount),
            'customerEmail' => $payerEmailAtTokenization,
            'paymentReference' => $paymentReference,
            'paymentDescription' => $narration,
            'currencyCode' => $currency,
            'contractCode' => $this->client->contractCode(),
            'apiKey' => $this->client->apiKey(),
        ], 'charging a stored Monnify card token');

        return DebitResult::fromMonnify($body);
    }

    protected function fetchTransactionStatus(string $transactionReference): array
    {
        return $this->client->get(
            MonnifyApiClient::TRANSACTION_STATUS_PATH . '/' . rawurlencode($transactionReference),
            [],
            'fetching a Monnify transaction status',
        );
    }

    protected function applyTransactionStatusToPayment(Payment $payment, array $status): void
    {
        $body = $status['responseBody'] ?? [];
        $paymentStatus = strtoupper((string) ($body['paymentStatus'] ?? ''));

        if ($paymentStatus === 'PAID') {
            $metadata = (array) $payment->metadata;
            $cardToken = Arr::get($body, 'cardDetails.cardToken');

            if (filled($cardToken)) {
                $metadata['monnify_card_token'] = $cardToken;
            }

            $paymentSource = TransactionCompleted::resolvePaymentSource(Arr::get($body, 'paymentSourceInformation'));

            if ($paymentSource !== null) {
                $metadata['monnify_payment_source'] = $paymentSource;
            }

            $payment->update([
                'is_success' => 1,
                'processor_transaction_reference' => $body['transactionReference'] ?? $payment->processor_transaction_reference,
                'processor_returned_amount' => isset($body['amountPaid']) ? (string) $body['amountPaid'] : null,
                'processor_returned_transaction_date' => $this->parseMonnifyDate($body['paidOn'] ?? null),
                'processor_returned_response_description' => $paymentStatus,
                'metadata' => $metadata,
            ]);

            return;
        }

        $canStillBeSuccessful = in_array($paymentStatus, ['PENDING', 'PROCESSING', 'IN_PROGRESS', ''], true);

        $payment->update([
            'is_success' => $canStillBeSuccessful ? null : 0,
            'processor_returned_response_description' => $paymentStatus ?: null,
        ]);
    }

    protected function parseMonnifyDate(?string $date): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y h:i:s A', $date) ?: null;
        } catch (\Throwable) {
            try {
                return new Carbon($date);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    protected function formatAmount(int | float | string | null $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    protected function resolvePayerName(Payment $payment): string
    {
        try {
            $name = $payment->getPayerName();
        } catch (\Throwable) {
            return 'Customer';
        }

        return filled($name) ? (string) $name : 'Customer';
    }

    protected function verifyWebhookSignature(Request $request): void
    {
        $signature = $request->header('monnify-signature');

        if (blank($signature)) {
            throw new UnknownWebhookException($this);
        }

        $secretKey = (string) config('laravel-multipay.monnify.secret_key');

        if ($secretKey === '') {
            throw new \Exception('Monnify secret key is not configured. Set MONNIFY_SECRET_KEY.');
        }

        $expectedSignature = hash_hmac('sha512', $request->getContent(), $secretKey);

        if (! hash_equals($expectedSignature, (string) $signature)) {
            throw new \Exception('Monnify webhook signature verification failed.');
        }
    }
}
