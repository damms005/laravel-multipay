<?php

namespace Damms005\LaravelMultipay\Contracts;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Exceptions\NonActionableWebhookPaymentException;

interface PaymentHandlerInterface
{
    /**
     * Render a form that will be used to send payment to the processor.
     * The rendered form should be auto-submitted to the payment handler gateway
     *
     * @param bool $getFormForLiveApiNotTest Payment providers must be able to generate forms for both live and test scenarios. We flip it here
     *
     */
    public function renderAutoSubmittedPaymentForm(Payment $payment, $redirect_or_callback_url, bool $getFormForLiveApiNotTest = false);

    /**
     * When payment provider sends transaction outcome to our callback url, we pass
     * the response each to all registered payment handlers, so the provider that is able to
     * process it should confirm that the payment_processor_name for the transaction is the
     * same as its own getUniquePaymentHandlerName() value, then handle the response and return the Payment object
     *
     * @param Request $paymentGatewayServerResponse
     *
     * @return Payment
     */
    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment;

    public function getHumanReadableTransactionResponse(Payment $payment): string;

    public static function getUniquePaymentHandlerName(): string;

    /**
     * For some reason (e.g. no response from server after successful payment, payment was fulfilled by some other
     * non-automated means, etc.) an initiated transaction was completed but not marked as successful. Payment handlers should implement
     * this method so as to support re-querying such transaction. In such implementation, payment handler should ensure to set the
     * payment as successful and ensure that all relevant/handler-specific properties of the transaction is set and saved to db
     */
    public function reQuery(Payment $existingPayment): ?Payment;

    /**
     * @throws UnknownWebhookException
     * @throws NonActionableWebhookPaymentException
     */
    public function handleExternalWebhookRequest(Request $paymentNotificationRequest): Payment;

    public function getTransactionReferenceName(): string;
}
