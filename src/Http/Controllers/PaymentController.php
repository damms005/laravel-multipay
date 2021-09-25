<?php

namespace Damms005\LaravelCashier\Http\Controllers;

use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Http\Requests\InitiatePaymentRequest;
use Damms005\LaravelCashier\Models\Payment;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Damms005\LaravelCashier\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaymentController extends Controller
{
	/**
	 * This is the first method to be called in the payment processing process
	 *
	 * @return \Illuminate\View\View|\Illuminate\Contracts\View\Factory
	 */
	public function confirm(InitiatePaymentRequest $initiatePaymentRequest)
	{
		$paymentHandler = PaymentService::getPaymentHandlerByName($initiatePaymentRequest->payment_processor);

		$amount = $initiatePaymentRequest->amount;

		$description = $initiatePaymentRequest->transaction_description;

		$view = $initiatePaymentRequest->filled('preferred_view') ? $initiatePaymentRequest->preferred_view : null;

		return PaymentService::storePaymentAndShowUserBeforeProcessing($paymentHandler, $initiatePaymentRequest->user_id, $amount, $description, $view);
	}

	/**
	 * This is the first method to be called in the payment processing pipeline.
	 *
	 * @param int $amountInLowestDenomination e.g. To pay NGN 500, pass 50000 (which is kobo - the lowest denomination for NGN)
	 * @param int $user_id
	 * @param PaymentHandlerInterface $payment_processor
	 * @param [type] $transaction_description
	 *
	 * @return View
	 */
	public static function makeAutoSubmittedFormRedirect(int $amountInLowestDenomination, $user, PaymentHandlerInterface $payment_processor, $transaction_description)
	{
		return view('laravel-cashier::payment.auto-submit-form', [
			"amount"                  => $amountInLowestDenomination,
			"user_id"                 => $user->id,
			"payment_processor"       => $payment_processor::getUniquePaymentHandlerName(),
			"transaction_description" => $transaction_description,

			// should a model be updated when transaction is successful? Provide details to such model:
			// "update_model_success"             => ModelRequest::class,
			// "update_model_unique_column"       => "id",
			// "update_model_unique_column_value" => $model_request->id,
		]);
	}

	public function sendToPaymentGateway(Request $request)
	{
		$request->validate([
			'transaction_reference' => 'required',
		]);

		/** @var Payment */
		$payment = Payment::with('user')->where('transaction_reference', $request->transaction_reference)->firstOrFail();

		//prevent duplicated transactions
		if ($payment->processor_returned_response_description) {
			return redirect()
				->back()
				->withErrors("Multiple transaction prevention system: The transaction with reference number {$request->transaction_reference} is already completed.")
				->withInput();
		}

		try {
			return (new BasePaymentHandler($payment->getPaymentProvider()))
				->sendTransactionToPaymentGateway($payment, route('payment.finished.callback_url'));
		} catch (\Throwable$th) {
			throw new \Exception("Unknown payment interface: " . $payment->payment_processor_name);
		}
	}

	public function handlePaymentGatewayResponse(Request $request)
	{
		return BasePaymentHandler::handleServerResponseForTransactionAndDisplayOutcome($request);
	}
}
