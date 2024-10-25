<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;

class UnifiedPayments extends BasePaymentHandler implements PaymentHandlerInterface
{
    protected const UP_SECRET_KEY = '0EC25CF8EEFD0706CBE93A7067D7F734BB1FC635BA226F99';
    protected const UP_SERVER_URL = "https://test.payarena.com";

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true): mixed
    {
        $response = Http::withHeaders([
            'accept' => 'application/json',
        ])
            ->post(self::UP_SERVER_URL . "/KOLBINS", [
                "amount" => $payment->original_amount_displayed_to_user,
                "currency" => "566",
                "description" => "{$payment->transaction_description}. (IP: " . request()->ip() . ")",
                "returnUrl" => $redirect_or_callback_url,
                "secretKey" => self::UP_SECRET_KEY,
                "fee" => 0,
            ]);

        if (!$response->successful()) {
            return PaymentService::redirectWithError($payment, ["Unified Payments could not process your transaction at the moment. Please try again later. " . $response->body()]);
        }

        $transactionId = $response->body();

        $payment->processor_transaction_reference = $transactionId;
        $payment->save();

        return $this->sendUserToPaymentGateway(self::UP_SERVER_URL . "/{$transactionId}");
    }

    /**
     *
     * @param Request $paymentGatewayServerResponse
     *
     * @return Payment
     */
    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        if (!$paymentGatewayServerResponse->has('trxId')) {
            return null;
        }

        $payment = Payment::where('processor_transaction_reference', $paymentGatewayServerResponse->trxId)->first();

        if (is_null($payment)) {
            return null;
        }

        if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
            return null;
        }

        $response = Http::get(self::UP_SERVER_URL . "/Status/{$payment->processor_transaction_reference}");

        throw_if(!$response->successful(), "Could not validate Unified Payment transaction");

        $responseBody = json_decode($response->body());

        $payment->processor_returned_response_description = $response->body();

        if (isset($responseBody->TranDateTime)) {
            $payment->processor_returned_transaction_date = Carbon::createFromFormat('d/m/Y H:i:s', $responseBody->TranDateTime);
        }

        $payment->processor_returned_amount = $responseBody->Amount;
        $payment->is_success = $responseBody->Status == "APPROVED";

        $payment->save();
        $payment->refresh();

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?ReQuery
    {
        throw new \Exception("Method not yet implemented");
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::handleExternalWebhookRequest
     */
    public function handleExternalWebhookRequest(Request $request): Payment
    {
        throw new UnknownWebhookException($this);
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

    protected function sendUserToPaymentGateway($unified_payment_redirect_url)
    {
        return redirect()->away($unified_payment_redirect_url);
    }
}
