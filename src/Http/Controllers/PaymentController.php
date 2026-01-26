<?php

namespace Damms005\LaravelMultipay\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Http\Requests\InitiatePaymentRequest;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

class PaymentController extends Controller
{
    /**
     * This is the first method to be called in the payment processing workflow
     *
     * @return \Illuminate\View\View|\Illuminate\Contracts\View\Factory
     */
    public function confirm(InitiatePaymentRequest $initiatePaymentRequest)
    {
        /** @var PaymentService */
        $paymentService = app()->make(PaymentService::class);

        $amount = $initiatePaymentRequest->amount;

        $description = $initiatePaymentRequest->transaction_description;
        $currency = $initiatePaymentRequest->currency;
        $transaction_reference = $initiatePaymentRequest->transaction_reference ?: strtoupper(Str::random(10));
        $metadata = $this->getMetadata($initiatePaymentRequest);

        $view = $initiatePaymentRequest->filled('preferred_view') ? $initiatePaymentRequest->preferred_view : null;

        return $paymentService->storePaymentAndShowUserBeforeProcessing(
            $initiatePaymentRequest->input('user_id'),
            $amount,
            $description,
            $currency,
            $transaction_reference,
            $view,
            $metadata,
            $initiatePaymentRequest->input('payment_processor'),
        );
    }

    public function sendToPaymentGateway(Request $request)
    {
        $request->validate([
            'transaction_reference' => 'required',
        ]);

        /** @var Payment */
        $payment = Payment::withTrashed()->where('transaction_reference', $request->transaction_reference)->firstOrFail();

        // prevent duplicated transactions
        if ($payment->processor_returned_response_description) {
            return PaymentService::redirectWithError($payment, ["Multiple transactions detected: The transaction with reference number {$request->transaction_reference} is already completed."]);
        }

        /** @var PaymentHandlerInterface */
        $basePaymentHandler = PaymentService::getHandlerFqcn($payment->payment_processor_name);

        return $basePaymentHandler->proceedToPaymentGateway($payment, route('payment.finished.callback_url'), true);
    }

    public function handlePaymentGatewayResponse(Request $request)
    {
        /** @var BasePaymentHandler */
        $handler = app()->make(BasePaymentHandler::class);

        return $handler::handleServerResponseForTransactionAndDisplayOutcome($request);
    }

    protected function getMetadata(InitiatePaymentRequest $initiatePaymentRequest): array|null
    {
        $metadata = $this->formatMetadata($initiatePaymentRequest->input('metadata'));

        if ($initiatePaymentRequest->filled('payer_name')) {
            $metadata['payer_name'] = $initiatePaymentRequest->payer_name;
        }
        if ($initiatePaymentRequest->filled('payer_email')) {
            $metadata['payer_email'] = $initiatePaymentRequest->payer_email;
        }
        if ($initiatePaymentRequest->filled('payer_phone')) {
            $metadata['payer_phone'] = $initiatePaymentRequest->payer_phone;
        }

        return $metadata;
    }

    /**
     * The metadata column is cast as AsArrayObject. Hence, we need to ensure that any
     * value saved is not a string, else we risk getting a doubly-encoded string in db
     * as an effect of the AsArrayObject db casting
     *
     * @param mixed $metadata
     *
     */
    protected function formatMetadata(mixed $metadata): array|null
    {
        if (empty($metadata)) {
            return null;
        }

        if (!is_string($metadata)) {
            return null;
        }

        return json_decode($metadata, true);
    }
}
