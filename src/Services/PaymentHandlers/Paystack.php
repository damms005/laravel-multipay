<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Yabacon\Paystack as PaystackHelper;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;

class Paystack extends BasePaymentHandler implements PaymentHandlerInterface
{
    protected $paystack_secret_key;

    public function __construct()
    {
        $this->paystack_secret_key = config("laravel-multipay.paystack_secret_key");

        if (empty($this->paystack_secret_key)) {
            // Paystack is currently the default payment handler (because
            // it is the easiest to setup and get up-adn-running for starters/testing). Hence,
            // let the error message be contextualized, so we have a better UX for testers/first-timers
            if ($this->isDefaultPaymentHandler()) {
                throw new \Exception("You set Paystack as your default payment handler, but no Paystack Sk found. Please provide SK for Paystack.");
            }

            throw new \Exception("Please provide SK for Paystack");
        }
    }

    public function renderAutoSubmittedPaymentForm(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true)
    {
        $transaction_reference = $payment->transaction_reference;
        $this->sendUserToPaymentGateway($redirect_or_callback_url, $this->getPayment($transaction_reference));
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
     *
     * @param Request $paymentGatewayServerResponse
     *
     * @return Payment
     */
    public function processValueForTransaction(string $transactionReferenceIdNumber): ?Payment
    {
        throw_if(empty($transactionReferenceIdNumber));

        $trx = $this->getPaystackTransaction($transactionReferenceIdNumber);

        // status should be true if there was a successful call
        if (!$trx->status) {
            exit($trx->message);
        }

        $payment = Payment::where('processor_transaction_reference', $transactionReferenceIdNumber)->firstOrFail();

        if ('success' == $trx->data->status) {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $this->give_value($transactionReferenceIdNumber, $trx);

            $payment->refresh();
        } else {
            $payment->update([
                'is_success' => 0,
                'processor_returned_response_description' => $trx->data->gateway_response,
            ]);
        }

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?Payment
    {
        throw new \Exception("Method not yet implemented");
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::handleExternalWebhookRequest
     */
    public function handleExternalWebhookRequest(Request $request): Payment
    {
        throw new UnknownWebhookException($this, $request);
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return "";
    }

    protected function getPaystackTransaction($paystackReference)
    {
        // Confirm that reference has not already gotten value
        // This would have happened most times if you handle the charge.success event.
        // If it has already gotten value by your records, you may call
        // perform_success()

        $paystack = new PaystackHelper($this->paystack_secret_key);

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        // (full sample verify response is here: https://developers.paystack.co/docs/verifying-transactions)
        return $paystack->transaction->verify(['reference' => $paystackReference]);
    }

    protected function convertAmountToValueRequiredByPaystack($original_amount_displayed_to_user)
    {
        return $original_amount_displayed_to_user * 100; //paystack only accept amount in kobo/lowest denomination of target currency
    }

    protected function sendUserToPaymentGateway(string $redirect_or_callback_url, Payment $payment)
    {
        $paystack = new PaystackHelper($this->paystack_secret_key);

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        $trx = $paystack->transaction->initialize(
            [
                'email' => $payment->user->email,
                'amount' => $this->convertAmountToValueRequiredByPaystack($payment->original_amount_displayed_to_user),
                'callback_url' => $redirect_or_callback_url,
            ]
        );

        // status should be true if there was a successful call
        if (!$trx->status) {
            exit($trx->message);
        }

        Payment::where('transaction_reference', $payment->transaction_reference)
            ->firstOrFail()
            ->update(['processor_transaction_reference' => $trx->data->reference]);

        // full sample initialize response is here: https://developers.paystack.co/docs/initialize-a-transaction
        // Get the user to click link to start payment or simply redirect to the url generated
        header('Location: ' . $trx->data->authorization_url);
        exit;
    }

    protected function give_value($paystackReference, $paystackResponse)
    {
        Payment::where('processor_transaction_reference', $paystackReference)
            ->firstOrFail()
            ->update([
                "is_success" => 1,
                "processor_returned_amount" => $paystackResponse->data->amount,
                "processor_returned_transaction_date" => new Carbon($paystackResponse->data->created_at),
                'processor_returned_response_description' => $paystackResponse->data->gateway_response,
            ]);
    }

    protected function perform_success($paystackReference)
    {
        return true;
    }
}
