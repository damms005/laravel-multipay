<?php

namespace Damms005\LaravelMultipay\Contracts;

use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Damms005\LaravelMultipay\Exceptions\MissingUserException;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Exceptions\NonActionableWebhookPaymentException;

interface PaymentHandlerInterface
{
    /**
     * Send user to the payment gateway. This will usually render an auto-submitted
     * form that will send user to the payment handler gateway, or a simple redirection.
     *
     * @param bool $getFormForLiveApiNotTest Payment providers must be able to generate forms for both live and test scenarios. We flip it here
     *
     */
    public function proceedToPaymentGateway(Payment $payment, $redirect_or_callback_url, bool $getFormForLiveApiNotTest = false): mixed;

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
    public function reQuery(Payment $existingPayment): ?ReQuery;

    /**
     * This check can be helpful in preventing double-payment.
     * If a payment is not yet completed and user re-initiates another
     * payment while status of such previous payment is not yet settled (i.e. successful or not)
     */
    public function paymentIsUnsettled(Payment $payment): bool;

    /**
     * If a payment was initiated but not completed, we can
     * resume such payment session (for supported payment handlers)
     *
     * Ideally, we should only return one of:
     * Illuminate\Contracts\View\View | Illuminate\Contracts\View\Factory | Illuminate\Http\RedirectResponse
     *
     * However, we are returning 'mixed' to provide support for some packages
     * that fiddle with default Laravel APIs (.e.g. Livewire replaces the
     * default redirector from the container. See:
     * https://github.com/livewire/livewire/blob/72ffd3833c96709121083ee1368c9ed62fdf9935/src/Features/SupportRedirects.php#L17)
     */
    public function resumeUnsettledPayment(Payment $payment): mixed;

    /**
     * @throws UnknownWebhookException
     * @throws NonActionableWebhookPaymentException
     * @throws MissingUserException
     */
    public function handleExternalWebhookRequest(Request $paymentNotificationRequest): Payment;

    public function getTransactionReferenceName(): string;

    /**
     * @return string Plan id
     */
    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string;

    /**
     * @return string Url to redirect user to
     */
    public function subscribeToPlan(User $user, PaymentPlan $plan, string $transactionReference): string;
}
