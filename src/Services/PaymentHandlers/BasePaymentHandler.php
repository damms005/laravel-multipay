<?php

namespace App\PaymentHandlers;

use App\Contracts\PaymentHandlerInterface;
use App\Events\ASuccessfulPaymentWasMade;
use App\Models\Payment;
use App\Notifications\TransactionCompleted;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BasePaymentHandler
{
	/**
	 * The Payment Handler processing this transaction
	 *
	 * @var PaymentHandlerInterface
	 */
	protected $paymentHandlerInterface;

	/**
	 * The Payment being made
	 *
	 * @var Payment
	 */
	protected $payment;

	public function __construct(PaymentHandlerInterface $paymentHandlerInterface)
	{
		//ensure the class is registered, so we are sure we will
		//have a handler when payment gateway server returns response
		if (!collect(self::getAllPaymentHandlers())->contains(get_class($paymentHandlerInterface))) {

			throw new \Exception("Unregistered payment handler: {$paymentHandlerInterface->getUniquePaymentHandlerName()}", 1);
		}

		$this->paymentHandlerInterface = $paymentHandlerInterface;
	}

	public static function getUniquePaymentHandlerName(): string
	{
		return Str::of(self::class)->afterLast("/");
	}

	/**
	 * @return string[]
	 */
	public static function getAllPaymentHandlers()
	{
		return collect([

			//IMPORTANT: THESE CLASSES MUST NOT HAVE CONSTRUCTOR(S). IF THEY MUST, CONSTRUCTOR(S) MUST NOT ACCEPT ANY
			// PARAMETER (this is because to handle payment provider response, we new-up instances of items in this array without constructors)

			Flutterwave::class,
			Paystack::class,
			Interswitch::class,
			UnifiedPayments::class,

		])
			->map(function (PaymentHandlerInterface $paymentHandlerInterface) {
				return $paymentHandlerInterface->getUniquePaymentHandlerName();
			});
	}

	/**
	 * This is where it all starts. It is like the initialization phase. User gets a chance to see summary of
	 * transaction details before the payment handler proceeds to process the transaction.
	 * Here is also where user gets the chance to save/note/print the
	 * transaction reference/summary before proceed to payment.
	 * This method should also create a record for this transaction in the database payments table.
	 *
	 *
	 * @return void
	 */
	public function storePaymentAndShowUserBeforeProcessing($user, $original_amount_displayed_to_user, string $transaction_description, string $completion_url = null, Request $optionalRequestForEloquentModelLinkage = null, $preferredView = null, $transaction_reference = null)
	{
		$payment = Payment::firstOrCreate([
			"user_id"                           => $user->id,
			"completion_url"                    => $completion_url,
			"transaction_reference"             => $transaction_reference ?? strtoupper(Str::random(10)),
			"payment_processor_name"            => $this->paymentHandlerInterface->getUniquePaymentHandlerName(),
			'transaction_description'           => $transaction_description,
			"original_amount_displayed_to_user" => $original_amount_displayed_to_user,
		]);

		if ($this->paymentHandlerInterface->getUniquePaymentHandlerName() == UnifiedPayments::getUniquePaymentHandlerName()) {
			$payment->customer_checkout_ip_address = request()->ip();
			$payment->save();
		}

		$post_payment_confirmation_submit = route('payment.confirmation.submit');

		if ($optionalRequestForEloquentModelLinkage) {
			$this->linkPaymentToEloquentModel($optionalRequestForEloquentModelLinkage, $payment);
		}

		$exports = compact('payment', 'post_payment_confirmation_submit', 'user');

		if (is_null($preferredView)) {
			return view('laravel-cashier::payment.generic-confirm_transaction', $exports);
		} else {
			return view($preferredView, $exports);
		}
	}

	public function sendTransactionToPaymentGateway(Payment $payment, $callback_url)
	{
		$this->paymentHandlerInterface->renderAutoSubmittedPaymentForm($payment, $callback_url);
	}

	/**
	 * Processes the outcome returned by payment gateway and return a view/response with details
	 * of the transaction (e.g. successful, fail, etc.)
	 *
	 * @param Request $paymentGatewayServerResponse
	 *
	 * @return void
	 */
	public static function handleServerResponseForTransactionAndDisplayOutcome(Request $paymentGatewayServerResponse)
	{
		/**
		 * @var Payment
		 */
		$payment = null;

		collect(self::getAllPaymentHandlers())
			->each(function (string $paymentHandlerClassName) use ($paymentGatewayServerResponse, &$payment) {

				$paymentHandler = new $paymentHandlerClassName();
				$payment        = $paymentHandler->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($paymentGatewayServerResponse);

				if ($payment) {
					if ($payment->is_success == 1) {
						event(new ASuccessfulPaymentWasMade($payment));
					}

					return false;
				}
			});

		$isJsonDescription  = false;
		$paymentDescription = null;

		if (!is_null($payment)) {
			$paymentDescription = json_decode($payment->processor_returned_response_description);
			$isJsonDescription  = !is_null($paymentDescription);

			if ($payment->getPaymentProvider() == UnifiedPayments::getUniquePaymentHandlerName()) {
				$payment->user->notify(TransactionCompleted::class);
			}
		}

		return view('laravel-cashier::payment.transaction-completed', compact('payment', 'isJsonDescription', 'paymentDescription'));
	}

	public function getPayment(string $transaction_reference): Payment
	{
		return Payment::where('transaction_reference', $transaction_reference)->firstOrFail();
	}

	public function getTransactedUser(string $transaction_reference)
	{
		return $this->getPayment($transaction_reference)->user;
	}

	private function linkPaymentToEloquentModel(Request $optionalRequestForEloquentModelLinkage, Payment $payment)
	{
		$validationEntries = [
			'update_model_success'             => 'required:',
			'update_model_unique_column'       => 'required_with:update_model_success',
			'update_model_unique_column_value' => 'required_with:update_model_unique_column',
		];

		$optionalRequestForEloquentModelLinkage->validate(array_keys($validationEntries));

		$model = (new $optionalRequestForEloquentModelLinkage->update_model_success)
			->where($optionalRequestForEloquentModelLinkage->update_model_unique_column, $optionalRequestForEloquentModelLinkage->update_model_unique_column_value)
			->first();

		if ($model) {
			$model->update(['payment_id' => $payment->id]);
		}
	}
}
