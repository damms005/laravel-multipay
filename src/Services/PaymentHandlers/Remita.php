<?php

namespace Damms005\LaravelCashier\Services\PaymentHandlers;

use Carbon\Carbon;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Remita extends BasePaymentHandler implements PaymentHandlerInterface
{
	protected const BASE_REQUEST_URL = "https://login.remita.net/remita";

    public function __construct()
    {
        //empty constructor, so we not forced to use parent's constructor
    }

    public function renderAutoSubmittedPaymentForm(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true)
    {
        $merchantId = config('laravel-cashier.remita_merchant_id');
        $serviceTypeId = $this->getServiceTypeId($payment);
        $orderId = $payment->transaction_reference;
        $totalAmount = $payment->original_amount_displayed_to_user;
        $apiKey = config('laravel-cashier.remita_api_key');
        $hash = hash("sha512", "{$merchantId}{$serviceTypeId}{$orderId}{$totalAmount}{$apiKey}");

        $postData = [
            "serviceTypeId" => $serviceTypeId,
            "amount" => $totalAmount,
            "orderId" => $orderId,
            "payerName" => $payment->user->name,
            "payerEmail" => $payment->user->email,
            "payerPhone" => $payment->user->phone,
            "description" => $payment->transaction_description,
        ];

        $response = Http::withHeaders([
            'accept' => 'application/json',
            "Authorization" => "remitaConsumerKey={$merchantId},remitaConsumerToken={$hash}",
        ])
            ->post(self::BASE_REQUEST_URL . "/exapp/api/v1/send/api/echannelsvc/merchant/api/paymentinit", $postData);

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

        $statusUrl = self::BASE_REQUEST_URL . "/exapp/api/v1/send/api/echannelsvc/{$merchantId}/{$rrr}/{$hash}/status.reg";

        $response = Http::withHeaders([
            'accept' => 'application/json',
            "Authorization" => "remitaConsumerKey={$merchantId},remitaConsumerToken={$hash}",
        ])
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
        $url = self::BASE_REQUEST_URL . "/ecomm/finalize.reg";
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
}
