<?php

namespace Damms005\LaravelCashier\Contracts;

use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Http\Request;

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
     * @param Request $request
     *
     * @return Payment
     */
    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $paymentGatewayServerResponse): ?Payment;

    public function getHumanReadableTransactionResponse(Payment $payment): string;

    public static function getUniquePaymentHandlerName(): string;
}
