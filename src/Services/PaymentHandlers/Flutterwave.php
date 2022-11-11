<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use KingFlamez\Rave\Facades\Rave as FlutterwaveRave;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;

class Flutterwave extends BasePaymentHandler implements PaymentHandlerInterface
{
    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true): mixed
    {
        $transaction_reference = $payment->transaction_reference;

        return $this->sendUserToPaymentGateway($redirect_or_callback_url, $this->getPayment($transaction_reference));
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return "";
    }

    protected function sendUserToPaymentGateway(string $redirect_or_callback_url, Payment $payment)
    {
        $flutterwaveReference = FlutterwaveRave::generateReference();

        // Enter the details of the payment
        $data = [
            'payment_options' => 'card',
            'amount' => $payment->original_amount_displayed_to_user,
            'email' => $payment->user->email,
            'tx_ref' => $flutterwaveReference,
            'currency' => "USD",
            'redirect_url' => $redirect_or_callback_url,
            'customer' => [
                'email' => $payment->user->email,
                "phone_number" => null,
                "name" => $payment->user->name,
            ],

            "customizations" => [
                "title" => 'Application fee payment',
                "description" => "Application fee payment",
            ],
        ];

        $paymentInitialization = FlutterwaveRave::initializePayment($data);

        throw_if($paymentInitialization['status'] !== 'success', "Cannot initialize Flutterwave payment");

        $url = $paymentInitialization['data']['link'];

        $payment->processor_transaction_reference = $flutterwaveReference;
        $payment->save();

        header('Location: ' . $url);

        exit;
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment
    {
        if (!$paymentGatewayServerResponse->has('tx_ref')) {
            return null;
        }

        $flutterwaveReference = $paymentGatewayServerResponse->get('tx_ref');
        $payment = Payment::where('processor_transaction_reference', $flutterwaveReference)->firstOrFail();

        $status = $paymentGatewayServerResponse->get('status');

        if ($status != 'successful') {
            $payment->processor_returned_response_description = $status;
            $payment->save();

            return $payment;
        }

        $transactionId = $paymentGatewayServerResponse->get('transaction_id');
        $flutterwavePaymentDetails = FlutterwaveRave::verifyTransaction($transactionId);

        if (!$this->isValidTransaction((array)$flutterwavePaymentDetails, $payment)) {
            $payment->processor_returned_response_description = "Invalid transaction";
            $payment->save();

            return $payment;
        }

        $payment = $this->giveValue($flutterwaveReference, (array)$flutterwavePaymentDetails);

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

    public function isValidTransaction(array $flutterwavePaymentDetails, Payment $payment)
    {
        return $flutterwavePaymentDetails['data']['amount'] == $payment->original_amount_displayed_to_user;
    }

    protected function giveValue($flutterwaveReference, array $flutterwavePaymentDetails): Payment
    {
        /**
         * @var Payment
         */
        $payment = Payment::where('processor_transaction_reference', $flutterwaveReference)
            ->firstOrFail();

        $payment->update([
            "is_success" => 1,
            "processor_returned_amount" => $flutterwavePaymentDetails['data']['amount'],
            "processor_returned_transaction_date" => new Carbon($flutterwavePaymentDetails['data']['created_at']),
            'processor_returned_response_description' => $flutterwavePaymentDetails['data']['processor_response'],
        ]);

        return $payment->fresh();
    }

    protected function performSuccess($flutterwaveReference)
    {
        return true;
    }
}
