<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Actions\CreateNewPayment;
use Damms005\LaravelMultipay\ValueObjects\RemitaResponse;
use Damms005\LaravelMultipay\Exceptions\MissingUserException;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Exceptions\WrongPaymentHandlerException;
use Damms005\LaravelMultipay\Exceptions\NonActionableWebhookPaymentException;

class Remita extends BasePaymentHandler implements PaymentHandlerInterface
{
    public $responseCodesIndicatingUnFulfilledTransactionState = [
        '021', // Transaction Pending
        '025', // Payment Reference generated
        '040', // Initial Request OK
        '041', // Transaction Forwarded for Processing
        '045', // Awaiting Payment Confirmation
        '058', // Pending Authorization
        '069', // New Transaction
        '070', // Awaiting Debit
        '071', // Undergoing Bank Processing
        '072', // Pending Credit
        '25', // Error Processing Request
    ];

    protected function getHttpRequestHeaders(string $merchantId, string $hash): array
    {
        $auth = "remitaConsumerKey={$merchantId},remitaConsumerToken={$hash}";

        return [
            'accept' => 'application/json',
            "Authorization" => $auth,
        ];
    }

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true): mixed
    {
        try {
            $rrr = $this->getRrrToInitiatePayment($payment);

            $payment->processor_transaction_reference = $rrr;
            $payment->save();

            return $this->sendUserToPaymentGateway($rrr);
        } catch (\Throwable $th) {
            return PaymentService::redirectWithError($payment, [$th->getMessage()]);
        }
    }

    protected function getRrrToInitiatePayment(Payment $payment): string
    {
        $merchantId = config('laravel-multipay.remita_merchant_id');
        $serviceTypeId = $this->getServiceTypeId($payment);
        $orderId = $payment->transaction_reference;
        $totalAmount = $payment->original_amount_displayed_to_user;
        $apiKey = config('laravel-multipay.remita_api_key');
        $hash = hash("sha512", "{$merchantId}{$serviceTypeId}{$orderId}{$totalAmount}{$apiKey}");
        $endpoint = $this->getBaseUrl() . "/exapp/api/v1/send/api/echannelsvc/merchant/api/paymentinit";
        $requestHeaders = $this->getHttpRequestHeaders($merchantId, $hash);

        $postData = [
            "serviceTypeId" => $serviceTypeId,
            "amount" => $totalAmount,
            "orderId" => $orderId,
            "payerName" => $payment->getPayerName(),
            "payerEmail" => $payment->getPayerEmail(),
            "payerPhone" => $payment->getPayerPhone(),
            "description" => $payment->transaction_description,
        ];

        $response = Http::withHeaders($requestHeaders)->post($endpoint, $postData);

        throw_if(!$response->successful(), "Remita could not process your transaction at the moment. Please try again later. " . $response->body());

        $responseJson = $response->json();

        throw_if(!array_key_exists('RRR', $responseJson), "An error occurred while generating your RRR. Please try again later. " . $response->body());

        return $responseJson['RRR'];
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $request): ?Payment
    {
        if (!$request->has('RRR')) {
            return null;
        }

        $rrr = $request->RRR;

        $payment = Payment::where('processor_transaction_reference', $rrr)
            ->first();

        if (is_null($payment)) {
            return null;
        }

        if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            return null;
        }

        $rrrQueryResponse = $this->queryRrr($rrr);

        return $this->useResponseToUpdatePayment($payment, RemitaResponse::from($rrrQueryResponse));
    }

    protected function queryRrr($rrr): \stdClass
    {
        $merchantId = config('laravel-multipay.remita_merchant_id');
        $apiKey = config('laravel-multipay.remita_api_key');
        $hash = hash("sha512", "{$rrr}{$apiKey}{$merchantId}");
        $requestHeaders = $this->getHttpRequestHeaders($merchantId, $hash);

        $statusUrl = $this->getBaseUrl() . "/exapp/api/v1/send/api/echannelsvc/{$merchantId}/{$rrr}/{$hash}/status.reg";

        $response = Http::withHeaders($requestHeaders)
            ->get($statusUrl);

        throw_if(
            !$response->successful(),
            "Remita could not get transaction details at the moment. Please try again later. " . $response->body()
        );

        return json_decode($response->body());
    }

    public function reQuery(Payment $existingPayment): ?ReQuery
    {
        if ($existingPayment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            throw new WrongPaymentHandlerException($this, $existingPayment);
        }

        if (empty($existingPayment->processor_transaction_reference)) {
            return null;
        }

        $rrr = $existingPayment->processor_transaction_reference;

        $payment = Payment::where('processor_transaction_reference', $rrr)
            ->first();

        throw_if(is_null($payment), "Could not reconcile Remita RRR with provided transaction");

        $rrrQueryResponse = $this->queryRrr($rrr);

        $payment = $this->useResponseToUpdatePayment($payment, RemitaResponse::from($rrrQueryResponse));

        return new ReQuery(
            payment: $payment,
            responseDescription: 'Response from gateway server: ' . json_encode((array)$rrrQueryResponse),
        );
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::handleExternalWebhookRequest
     */
    public function handleExternalWebhookRequest(Request $request): Payment
    {
        if (!$request->filled('rrr')) {
            throw new UnknownWebhookException($this);
        }

        $rrr = $request->rrr;

        $rrrQueryResponse = $this->queryRrr($rrr);

        if (!property_exists($rrrQueryResponse, "status")) {
            throw new NonActionableWebhookPaymentException($this, "No 'status' property in Remita server response", $request);
        }

        $payment = $this->getPaymentByRrr($rrr);

        if (is_null($payment)) {
            throw new NonActionableWebhookPaymentException($this, "Cannot fetch payment using RRR", $request);
        }

        $user = $this->getUserByEmail($rrrQueryResponse->email);

        if (is_null($user)) {
            throw new MissingUserException($this, "Cannot get user by email. Email was {$rrrQueryResponse->email}");
        }

        $payment = $this->useResponseToUpdatePayment($payment, RemitaResponse::from($rrrQueryResponse));

        return $payment;
    }

    protected function createNewPayment(User $user, \stdClass $responseBody): Payment
    {
        return (new CreateNewPayment())->execute(
            $this->getUniquePaymentHandlerName(),
            $user->id,
            null,
            Str::random(10),
            'NGN',
            $responseBody->description,
            $responseBody->amount,
            []
        );
    }

    protected function getUserByEmail($email)
    {
        return User::whereEmail($email)->first();
    }

    protected function getPaymentByRrr($rrr)
    {
        return Payment::where('processor_transaction_reference', $rrr)
            ->first();
    }

    protected function useResponseToUpdatePayment(Payment $payment, RemitaResponse $rrrQueryResponse): Payment
    {
        $payment->processor_returned_response_description = json_encode($rrrQueryResponse);

        if (isset($rrrQueryResponse->paymentDate)) {
            $payment->processor_returned_transaction_date = Carbon::parse($rrrQueryResponse->paymentDate);
        }

        $payment->is_success = $rrrQueryResponse->status == "00";

        // To re-query Remita transactions, users usually depend on the nullity is_success, such that
        // if it is NULL (its original/default value), the user knows it is eligible to be retried. Since we
        // cannot dependably rely on Remita to always push status of successful transactions (especially bank transactions),
        // users usually re-query Remita at intervals. We should therefore not set is_success prematurely. We should set it only
        // when we are sure that user cannot reasonably
        if ($this->isTransactionCanStillBeReQueried($rrrQueryResponse->status)) {
            $payment->is_success = null;
        }

        if ($payment->is_success) {
            $payment->processor_returned_amount = $rrrQueryResponse->amount;
        }

        $payment->save();
        $payment->refresh();

        return $payment;
    }

    protected function isTransactionCanStillBeReQueried(string $paymentStatus)
    {
        return in_array($paymentStatus, $this->responseCodesIndicatingUnFulfilledTransactionState);
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return $responseCode;
    }

    protected function convertAmountToValueRequiredByPaystack($original_amount_displayed_to_user)
    {
        return $original_amount_displayed_to_user * 100; //paystack only accept amount in kobo/lowest denomination of target currency
    }

    protected function sendUserToPaymentGateway(string $rrr)
    {
        $url = $this->getBaseUrl() . "/ecomm/finalize.reg";
        $merchantId = config('laravel-multipay.remita_merchant_id');
        $apiKey = config('laravel-multipay.remita_api_key');
        $hash = hash("sha512", "{$merchantId}{$rrr}{$apiKey}");
        $responseUrl = route('payment.finished.callback_url');

        return view('laravel-multipay::payment-handler-specific.remita-auto_submitted_form', [
            'url' => $url,
            'rrr' => $rrr,
            'hash' => $hash,
            'merchantId' => $merchantId,
            'responseUrl' => $responseUrl,
        ]);
    }

    public function getServiceTypeId(Payment $payment)
    {
        // Prioritize user-defined service id
        if (Arr::has($payment->metadata, 'remita_service_id')) {
            return Arr::get($payment->metadata, 'remita_service_id');
        } else {
            throw new \Exception('Missing Remita service id. Please specify the Remita service id in the payment metadata json.');
        }
    }

    public function getBaseUrl()
    {
        return config('laravel-multipay.remita_base_request_url');
    }

    public function getTransactionReferenceName(): string
    {
        return 'RRR Code';
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        if (is_null($payment->processor_returned_response_description)) {
            return true;
        }

        $returnedResponse = json_decode($payment->processor_returned_response_description, true);

        if (!$returnedResponse) {
            return true;
        }

        if ($this->isTransactionCanStillBeReQueried($returnedResponse['status'])) {
            return true;
        }

        $internalErrorOccurred = $returnedResponse['status'] == '998';

        if ($internalErrorOccurred) {
            return true;
        }

        return false;
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        if (!$payment->processor_transaction_reference) {
            throw new \Exception("Attempt was made to resume a payment that does not have RRR. Payment id is {$payment->id}");
        }

        return $this->sendUserToPaymentGateway($payment->processor_transaction_reference);
    }
}
