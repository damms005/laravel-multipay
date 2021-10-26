<?php

namespace Damms005\LaravelCashier\Services\PaymentHandlers;

use Carbon\Carbon;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class Remita extends BasePaymentHandler implements PaymentHandlerInterface
{
	protected const BASE_REQUEST_URL = "https://remitademo.net/remita";

	public function __construct()
	{
		//empty constructor, so we not forced to use parent's constructor
	}

	public function renderAutoSubmittedPaymentForm(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true)
	{
		$merchantId    = config('laravel-cashier.remita_merchant_id');
		$serviceTypeId = $this->getServiceTypeId($payment);
		$orderId       = $payment->transaction_reference;
		$totalAmount   = $payment->getAmountInNaira();
		$apiKey        = config('laravel-cashier.remita_api_key');
		$hash          = hash("sha512", "{$merchantId}{$serviceTypeId}{$orderId}{$totalAmount}{$apiKey}");

		$response = Http::withHeaders([
			'accept'        => 'application/json',
			"Authorization" => "remitaConsumerKey={$merchantId},remitaConsumerToken={$hash}",
		])
			->post(self::BASE_REQUEST_URL . "/exapp/api/v1/send/api/echannelsvc/merchant/api/paymentinit", [
				"serviceTypeId" => $serviceTypeId,
				"amount"        => $totalAmount,
				"orderId"       => $orderId,
				"payerName"     => $payment->user->name,
				"payerEmail"    => $payment->user->email,
				"payerPhone"    => $payment->user->phone,
				"description"   => $payment->transaction_description,
			]);

		if ($response->successful()) {
			$rrr = $response->body();

			$payment->processor_transaction_reference = $rrr;
			$payment->save();

			return $this->sendUserToPaymentGateway($rrr);
		} else {
			return redirect()->back()->withErrors("Remita could not process your transaction at the moment. Please try again later. " . $response->body())->withInput();
		}
	}

	public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
	{
		dd($paymentGatewayServerResponse);

		if (!$paymentGatewayServerResponse->has('rrr')) {
			return null;
		}

		$rrr = $paymentGatewayServerResponse->rrr;

		$payment = Payment::where('processor_transaction_reference', $rrr)->first();

		if (is_null($payment)) {
			return null;
		}

		if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
			return null;
		}

		$merchantId    = config('laravel-cashier.remita_merchant_id');
		$serviceTypeId = $this->getServiceTypeId($payment);
		$orderId       = $payment->transaction_reference;
		$totalAmount   = $payment->getAmountInNaira();
		$apiKey        = config('laravel-cashier.remita_api_key');
		$hash          = hash("sha512", "{$merchantId}{$serviceTypeId}{$orderId}{$totalAmount}{$apiKey}");

		$statusUrl = self::BASE_REQUEST_URL . "/exapp/api/v1/send/api/echannelsvc/{$merchantId}/{$rrr}/{$hash}/status.reg";

		$response = Http::withHeaders([
			'accept'        => 'application/json',
			"Authorization" => "remitaConsumerKey={$merchantId},remitaConsumerToken={$hash}",
		])
			->post($statusUrl);

		if (!$response->successful()) {
			return redirect()->back()->withErrors("Remita could not process your transaction at the moment. Please try again later. " . $response->body())->withInput();
		}

		$responseBody = json_decode($response->body());

		$payment->processor_returned_response_description = $response->body();

		if (isset($responseBody->TranDateTime)) {
			$payment->processor_returned_transaction_date = Carbon::createFromFormat('d/m/Y H:i:s', $responseBody->TranDateTime);
		}

		$payment->processor_returned_amount = $responseBody->Amount;
		$payment->is_success                = $responseBody->message == "Approved";

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
		$url         = self::BASE_REQUEST_URL . "/ecomm/finalize.reg";
		$merchantId  = config('laravel-cashier.remita_merchant_id');
		$hash        = hash("sha512", $merchantId);
		$responseUrl = route('payment.finished.callback_url');

		return view('laravel-cashier::payment-handler-specific.remita-auto_submitted_form',
			compact(
				'url',
                'rrr',
				'hash',
				'merchantId',
				'responseUrl',
			));
	}

	public function getServiceTypeId(Payment $payment)
	{
        $serviceTypeId = "4430731";

		return $serviceTypeId?: $payment->id;
	}
}
