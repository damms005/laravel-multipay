<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Yabacon\Paystack as PaystackHelper;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Webhooks\Paystack\ChargeSuccess;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\ValueObjects\PaystackVerificationResponse;

class Paystack extends BasePaymentHandler implements PaymentHandlerInterface
{
    protected $secret_key;

    public function __construct()
    {
        $this->secret_key = config("laravel-multipay.paystack_secret_key");

        if (empty($this->secret_key)) {
            // Paystack is currently the default payment handler (because
            // it is the easiest to setup and get up-and-running for starters/testing). Hence,
            // let the error message be contextualized, so we have a better UX for testers/first-timers
            if ($this->isDefaultPaymentHandler()) {
                throw new \Exception("You set Paystack as your default payment handler, but no Paystack Sk found. Please provide SK for Paystack.");
            }
        }
    }

    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, $getFormForTesting = true): mixed
    {
        $transaction_reference = $payment->transaction_reference;

        return $this->sendUserToPaymentGateway($redirect_or_callback_url, $this->getPayment($transaction_reference));
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
     */
    public function processValueForTransaction(string $paystackReference): ?Payment
    {
        throw_if(empty($paystackReference));

        $verificationResponse = $this->verifyPaystackTransaction($paystackReference);

        // status should be true if there was a successful call
        if (!$verificationResponse->status) {
            throw new \Exception($verificationResponse->message);
        }

        $payment = $this->resolveLocalPayment($paystackReference, $verificationResponse);

        if ('success' === $verificationResponse->data['status']) {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $this->giveValue($payment->transaction_reference, $verificationResponse);

            $payment->refresh();
        } else {
            $payment->update([
                'is_success' => 0,
                'processor_returned_response_description' => $verificationResponse->data['gateway_response'],
            ]);
        }

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?ReQuery
    {
        try {
            $verificationResponse = $this->verifyPaystackTransaction($existingPayment->processor_transaction_reference);
        } catch (\Throwable $th) {
            return new ReQuery($existingPayment, ['error' => $th->getMessage()]);
        }

        // status should be true if there was a successful call
        if (!$verificationResponse->status) {
            throw new \Exception($verificationResponse->message);
        }

        $payment = $this->resolveLocalPayment($existingPayment->processor_transaction_reference, $verificationResponse);

        if ('success' === $verificationResponse->data['status']) {
            if ($payment->payment_processor_name != $this->getUniquePaymentHandlerName()) {
                return null;
            }

            $this->giveValue($payment->transaction_reference, $verificationResponse);
        } else {
            $canStillBeSuccessful = in_array($verificationResponse->data['status'], ['ongoing', 'pending', 'processing', 'queued']);
            $payment->update([
                'is_success' => $canStillBeSuccessful
                    ? null // so can still be selected for requery
                    : false,
                'processor_returned_response_description' => $verificationResponse->data['gateway_response'],
            ]);
        }

        return new ReQuery(
            payment: $payment,
            responseDetails: (array)$verificationResponse,
        );
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::handleExternalWebhookRequest
     */
    public function handleExternalWebhookRequest(Request $request): Payment
    {
        $webhookEvents = [
            ChargeSuccess::class,
        ];

        foreach ($webhookEvents as $webhookEvent) {
            /** @var WebhookHandler */
            $handler = new $webhookEvent();

            if ($this->canHandleWebhook($handler, $request)) {
                return $handler->handle($request);
            }
        }

        throw new UnknownWebhookException($this);
    }

    protected function canHandleWebhook(WebhookHandler $handler, Request $request): bool
    {
        return $handler->isHandlerFor($request);
    }

    public function getHumanReadableTransactionResponse(Payment $payment): string
    {
        return '';
    }

    public function convertResponseCodeToHumanReadable($responseCode): string
    {
        return "";
    }

    protected function verifyPaystackTransaction($paystackReference): PaystackVerificationResponse
    {
        // Confirm that reference has not already gotten value
        // This would have happened most times if you handle the charge.success event.
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        // (full sample verify response is here: https://developers.paystack.co/docs/verifying-transactions)

        return PaystackVerificationResponse::from(
            $paystack->transaction->verify(['reference' => $paystackReference])
        );
    }

    protected function convertAmountToValueRequiredByPaystack($original_amount_displayed_to_user)
    {
        return $original_amount_displayed_to_user * 100; //paystack only accept amount in kobo/lowest denomination of target currency
    }

    protected function sendUserToPaymentGateway(string $redirect_or_callback_url, Payment $payment)
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $payload = [
            'email' => $payment->getPayerEmail(),
            'amount' => $this->convertAmountToValueRequiredByPaystack($payment->original_amount_displayed_to_user),
            'callback_url' => $redirect_or_callback_url,
        ];

        $splitCode = Arr::get($payment->metadata, 'split_code');
        if (boolval(trim($splitCode))) {
            $payload['split_code'] = $splitCode;
        }

        // the code below throws an exception if there was a problem completing the request,
        // else returns an object created from the json response
        $trx = $paystack->transaction->initialize($payload);

        // status should be true if there was a successful call
        if (!$trx->status) {
            throw new \Exception($trx->message);
        }

        $payment = Payment::where('transaction_reference', $payment->transaction_reference)
            ->firstOrFail();

        $metadata = is_null($payment->metadata) ? [] : (array)$payment->metadata;

        $payment->update([
            'processor_transaction_reference' => $trx->data->reference,
            'metadata' => array_merge($metadata, [
                'paystack_authorization_url' => $trx->data->authorization_url
            ]),
        ]);

        // full sample initialize response is here: https://developers.paystack.co/docs/initialize-a-transaction
        // Get the user to click link to start payment or simply redirect to the url generated
        return redirect()->away($trx->data->authorization_url);
    }

    protected function giveValue(string $transactionReference, PaystackVerificationResponse $paystackResponse)
    {
        Payment::where('transaction_reference', $transactionReference)
            ->firstOrFail()
            ->update([
                "is_success" => 1,
                "processor_returned_amount" => $paystackResponse->data['amount'],
                "processor_returned_transaction_date" => new Carbon($paystackResponse->data['created_at']),
                'processor_returned_response_description' => $paystackResponse->data['gateway_response'],
            ]);
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        return is_null($payment->is_success);
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        if (!array_key_exists('paystack_authorization_url', (array)$payment->metadata)) {
            throw new \Exception("Attempt was made to resume a Paystack payment that does not have payment URL. Payment id is {$payment->id}");
        }

        return redirect()->away($payment->metadata['paystack_authorization_url']);
    }

    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string
    {
        $paystack = app()->make(PaystackHelper::class, ['secret_key' => $this->secret_key]);

        $paystack->plan->create([
            'name' => $name,
            'amount' => $amount, // in lowest denomination. e.g. kobo
            'interval' => $interval, // hourly, daily, weekly, monthly, quarterly, biannually (every 6 months) and annually
            'description' => $description,
            'currency' => $currency, // Allowed values are NGN, GHS, ZAR or USD
        ]);

        return '';
    }

    protected function resolveLocalPayment(string $paystackReferenceNumber, PaystackVerificationResponse $verificationResponse): Payment
    {
        $isPosTerminalTransaction = is_object($verificationResponse->data['metadata']) &&
        ($verificationResponse->data['metadata']->reference ?? false);

        return Payment::query()
            /**
             * normal transactions
             */
            ->where('processor_transaction_reference', $paystackReferenceNumber)

            /**
             * terminal POS transactions
             */
            ->when($isPosTerminalTransaction, function ($query) use ($verificationResponse) {
                return $query->orWhere(
                    'metadata->response->data->metadata->reference',
                    $verificationResponse->data['metadata']->reference,
                );
            })
            ->firstOrFail();
    }
}
