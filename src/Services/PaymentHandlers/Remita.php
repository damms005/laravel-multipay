<?php

namespace Damms005\LaravelCashier\Services\PaymentHandlers;

use Carbon\Carbon;
use Damms005\LaravelCashier\Actions\CreateNewPayment;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;

class Remita extends BasePaymentHandler implements PaymentHandlerInterface
{
    public function __construct()
    {
        //empty constructor, so we not forced to use parent's constructor
    }

    protected function getHttpRequestHeaders(string $merchantId, string $hash): array
    {
        $auth = "remitaConsumerKey={$merchantId},remitaConsumerToken={$hash}";

        return [
            'accept' => 'application/json',
            "Authorization" => $auth,
        ];
    }

    public function renderAutoSubmittedPaymentForm(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true)
    {
        $merchantId = config('laravel-cashier.remita_merchant_id');
        $serviceTypeId = $this->getServiceTypeId($payment);
        $orderId = $payment->transaction_reference;
        $totalAmount = $payment->original_amount_displayed_to_user;
        $apiKey = config('laravel-cashier.remita_api_key');
        $hash = hash("sha512", "{$merchantId}{$serviceTypeId}{$orderId}{$totalAmount}{$apiKey}");
        $endpoint = $this->getBaseUrl() . "/exapp/api/v1/send/api/echannelsvc/merchant/api/paymentinit";
        $requestHeaders = $this->getHttpRequestHeaders($merchantId, $hash);

        $postData = [
            "serviceTypeId" => $serviceTypeId,
            "amount" => $totalAmount,
            "orderId" => $orderId,
            "payerName" => $payment->user->name,
            "payerEmail" => $payment->user->email,
            "payerPhone" => $payment->user->phone,
            "description" => $payment->transaction_description,
        ];

        $response = Http::withHeaders($requestHeaders)->post($endpoint, $postData);

        if (! $response->successful()) {
            return response("Remita could not process your transaction at the moment. Please try again later. " . $response->body());
        }

        $responseJson = $response->json();

        if (! array_key_exists('RRR', $responseJson)) {
            return response("An error occurred while generating your RRR. Please try again later. " . $response->body());
        }

        $rrr = $responseJson['RRR'];

        $payment->processor_transaction_reference = $rrr;
        $payment->save();

        return $this->sendUserToPaymentGateway($rrr);
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        if (! $paymentGatewayServerResponse->has('RRR')) {
            return null;
        }

        $rrr = $paymentGatewayServerResponse->RRR;

        $payment = Payment::where('processor_transaction_reference', $rrr)
            ->first();

        if (is_null($payment)) {
            return null;
        }

        if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            return null;
        }

        $merchantId = config('laravel-cashier.remita_merchant_id');
        $apiKey = config('laravel-cashier.remita_api_key');
        $hash = hash("sha512", "{$rrr}{$apiKey}{$merchantId}");
        $requestHeaders = $this->getHttpRequestHeaders($merchantId, $hash);

        $statusUrl = $this->getBaseUrl() . "/exapp/api/v1/send/api/echannelsvc/{$merchantId}/{$rrr}/{$hash}/status.reg";

        $response = Http::withHeaders($requestHeaders)
            ->get($statusUrl);

        throw_if(
            ! $response->successful(),
            "Remita could not process your transaction at the moment. Please try again later. " . $response->body()
        );

        $responseBody = json_decode($response->body());

        $payment->processor_returned_response_description = $response->body();

        if (isset($responseBody->paymentDate)) {
            $payment->processor_returned_transaction_date = Carbon::parse($responseBody->paymentDate);
        }

        $payment->processor_returned_amount = $responseBody->amount;
        $payment->is_success = $responseBody->status == "00";

        $payment->save();
        $payment->refresh();

        return $payment;
    }

    protected function queryRrr($rrr): stdClass
    {
        $merchantId = config('laravel-cashier.remita_merchant_id');
        $apiKey = config('laravel-cashier.remita_api_key');
        $hash = hash("sha512", "{$rrr}{$apiKey}{$merchantId}");
        $requestHeaders = $this->getHttpRequestHeaders($merchantId, $hash);

        $statusUrl = $this->getBaseUrl() . "/exapp/api/v1/send/api/echannelsvc/{$merchantId}/{$rrr}/{$hash}/status.reg";

        $response = Http::withHeaders($requestHeaders)
            ->get($statusUrl);

        throw_if(
            ! $response->successful(),
            "Remita could not get transaction details at the moment. Please try again later. " . $response->body()
        );

        return json_decode($response->body());
    }

    public function reQuery(Payment $existingPayment): ?Payment
    {
        Log::info("Remita trying requery");

        if ($existingPayment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            Log::info("It is not a Remita transaction");
            return null;
        }

        if (empty($existingPayment->processor_transaction_reference)) {
            Log::info("Payment does not have RRR");
            return null;
        }

        $rrr = $existingPayment->processor_transaction_reference;

        $payment = Payment::where('processor_transaction_reference', $rrr)
            ->first();

        if (is_null($payment)) {
            Log::info("Payment with RRR {$rrr} not found");
            return null;
        }

        $responseBody = $this->queryRrr($rrr);

        $payment = $this->updateSuccessfulPayment($payment, $responseBody);

        Log::info("Remita: Payment found and updated");

        return $payment;
    }

    /**
     * @see \Damms005\LaravelCashier\Contracts\PaymentHandlerInterface::handlePaymentNotification
     */
    public function handlePaymentNotification(Request $request): Payment|bool|null
    {
        if (!$request->filled('rrr')) {
            return null;
        }

        $rrr = $request->rrr;

        $responseBody = $this->queryRrr($rrr);

        if (! property_exists($responseBody, "status")) {
            return false;
        }

        if ($responseBody->status != "00") {
            return false;
        }

        $payment = $this->getPaymentByRrr($rrr);

        if (!is_null($payment)) {
            //it has been previously handled
            return false;
        }

        $user = $this->getUserByEmail($responseBody->email);

        if (is_null($user)) {
            return false;
        }

        $payment = $this->createNewPayment($user, $responseBody);

        $payment = $this->updateSuccessfulPayment($payment, $responseBody);

        return $payment;
    }

    protected function createNewPayment(User $user, stdClass $responseBody):Payment
    {
        return (new CreateNewPayment)->execute(
            $this->getUniquePaymentHandlerName(),
            $user->id,
            null,
            Str::random(10),
            'NGN',
            $responseBody->description,
            $responseBody->amount,
            ""
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

    protected function updateSuccessfulPayment(Payment $payment, stdClass $responseBody): Payment
    {
        $payment->processor_returned_response_description = json_encode($responseBody);

        if (isset($responseBody->paymentDate)) {
            $payment->processor_returned_transaction_date = Carbon::parse($responseBody->paymentDate);
        }

        $payment->processor_returned_amount = $responseBody->amount;
        $payment->is_success = $responseBody->status == "00";

        $payment->save();
        $payment->refresh();

        return $payment;
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
        $merchantId = config('laravel-cashier.remita_merchant_id');
        $apiKey = config('laravel-cashier.remita_api_key');
        $hash = hash("sha512", "{$merchantId}{$rrr}{$apiKey}");
        $responseUrl = route('payment.finished.callback_url');

        return view(
            'laravel-cashier::payment-handler-specific.remita-auto_submitted_form',
            compact(
                'url',
                'rrr',
                'hash',
                'merchantId',
                'responseUrl',
            )
        );
    }

    public function getServiceTypeId(Payment $payment)
    {
        $availableServiceTypes = config("laravel-cashier.remita_service_types");
        $serviceTypeConfigKey = $this->getRemitaServiceTypeConfigKey($payment->transaction_description);

        throw_if(! is_array($availableServiceTypes), "Remita service types not well defined");

        throw_if(
            ! array_key_exists($serviceTypeConfigKey, $availableServiceTypes),
            "Remita service types configuration does not have definition for '{$serviceTypeConfigKey}"
        );

        return $availableServiceTypes[$serviceTypeConfigKey];
    }

    public function getRemitaServiceTypeConfigKey(string $paymentDescription)
    {
        return Str::snake($paymentDescription);
    }

    public function getBaseUrl()
    {
        return config('laravel-cashier.remita_base_request_url');
    }
}
