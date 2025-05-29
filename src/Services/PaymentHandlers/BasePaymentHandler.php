<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\ValueObjects\ReQuery;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Damms005\LaravelMultipay\Services\PaymentService;
use Damms005\LaravelMultipay\Actions\CreateNewPayment;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;

abstract class BasePaymentHandler
{
    public const WEBHOOK_OKAY = 'OK';

    /**
     * FQCN of payment providers.
     * These payment providers are resolved from the Laravel Container, so
     * you may type-hint any dependency they have in their class constructors.
     */
    protected const PAYMENT_PROVIDERS_FQCNs = [
        Paystack::class,
        Remita::class,
        Flutterwave::class,
        // Interswitch::class,
        // UnifiedPayments::class,
    ];

    /**
     * The Payment being made
     *
     * @var Payment
     */
    protected $payment;

    public function __construct()
    {
        //ensure we will have a handler when payment gateway server returns response
        if ($this->isUnregisteredPaymentHandler()) {
            throw new \Exception("Unregistered payment handler: {$this->getUniquePaymentHandlerName()}", 1);
        }
    }

    protected function isUnregisteredPaymentHandler()
    {
        return !collect(self::PAYMENT_PROVIDERS_FQCNs)->contains(static::class);
    }

    public static function getNamesOfPaymentHandlers()
    {
        return collect(self::PAYMENT_PROVIDERS_FQCNs)
            ->mapWithKeys(function (string $paymentHandlerFqcn) {
                /** @var PaymentHandlerInterface */
                $paymentHandler = new $paymentHandlerFqcn();

                return [$paymentHandlerFqcn => $paymentHandler->getUniquePaymentHandlerName()];
            });
    }

    public function getTransactionReferenceName(): string
    {
        return $this->getUniquePaymentHandlerName() . ' Transaction Reference';
    }

    /**
     * This is where it all starts. It is like the initialization phase. User gets a chance to see summary of
     * transaction details before the payment handler proceeds to process the transaction.
     * Here is also where user gets the chance to save/note/print the
     * transaction reference/summary before proceed to payment.
     * This method should also create a record for this transaction in the database payments table.
     *
     */
    public function storePaymentAndShowUserBeforeProcessing(?int $user_id, $original_amount_displayed_to_user, string $transaction_description, $currency, string $transaction_reference, ?string $completion_url = null, ?Request $optionalRequestForEloquentModelLinkage = null, $preferredView = null, ?array $metadata = null)
    {
        $payment = (new CreateNewPayment())->execute(
            $this->getUniquePaymentHandlerName(),
            $user_id,
            $completion_url,
            $transaction_reference,
            $currency,
            $transaction_description,
            $original_amount_displayed_to_user,
            $metadata
        );

        if ($this->getUniquePaymentHandlerName() == UnifiedPayments::getUniquePaymentHandlerName()) {
            $payment->customer_checkout_ip_address = request()->ip();
            $payment->save();
        }

        $post_payment_confirmation_submit = route('payment.confirmation.submit');

        if ($optionalRequestForEloquentModelLinkage) {
            $this->linkPaymentToEloquentModel($optionalRequestForEloquentModelLinkage, $payment);
        }

        $instructions = config('laravel-multipay.payment_confirmation_notice');

        $exports = [
            'instructions' => $instructions,
            'currency' => $currency,
            'payment' => $payment,
            'post_payment_confirmation_submit' => $post_payment_confirmation_submit,
            'user_id' => $user_id
        ];

        if (empty($preferredView)) {
            return view('laravel-multipay::generic-confirm_transaction', $exports);
        } else {
            return view($preferredView, $exports);
        }
    }

    public function isDefaultPaymentHandler(): bool
    {
        return self::class === config('laravel-multipay.default_payment_handler_fqcn');
    }

    /**
     * Processes the outcome returned by payment gateway and return a view/response with details
     * of the transaction (e.g. successful, fail, etc.)
     *
     * @param Request $paymentGatewayServerResponse
     */
    public static function handleServerResponseForTransactionAndDisplayOutcome(Request $paymentGatewayServerResponse)
    {
        $payment = self::sendNotificationForSuccessFulPayment($paymentGatewayServerResponse);

        throw_if(!$payment, "Could not retrieve payment");

        [$paymentDescription, $isJsonDescription] = self::getPaymentDescription($payment);

        if ($payment->is_success) {
            if (self::paymentHasCustomSuccessPage($payment)) {
                return self::redirectToCustomSuccessPage($payment);
            }
        }

        return view('laravel-multipay::transaction-completed', [
            'payment' => $payment,
            'isJsonDescription' => $isJsonDescription,
            'paymentDescription' => $paymentDescription
        ]);
    }

    protected static function paymentHasCustomSuccessPage(Payment $payment)
    {
        /** @var ArrayObject */
        $metadata = $payment->metadata;

        if (!$payment->metadata) {
            return;
        }

        $metadata = $metadata->toArray();

        return array_key_exists('completion_url', $metadata)
            && trim($metadata['completion_url']);
    }

    protected static function redirectToCustomSuccessPage(Payment $payment)
    {
        $url = $payment->metadata['completion_url'] . "?transaction_reference=" . $payment->transaction_reference;

        return redirect()->away($url);
    }

    protected static function sendNotificationForSuccessFulPayment(Request $paymentGatewayServerResponse): ?Payment
    {
        /**
         * @var Payment
         */
        $payment = null;

        /** @var PaymentService */
        $paymentService = app()->make(PaymentService::class);

        collect(self::getNamesOfPaymentHandlers())
            ->each(function (string $paymentHandlerName) use ($paymentGatewayServerResponse, $paymentService, &$payment) {
                $payment = $paymentService->handleGatewayResponse($paymentGatewayServerResponse, $paymentHandlerName);

                if ($payment) {
                    if ($payment->is_success == 1) {
                        event(new SuccessfulLaravelMultipayPaymentEvent($payment));
                    }

                    return false;
                }
            });

        return $payment;
    }

    /**
     * @return array [paymentDescription, isJsonDescription]
     */
    protected static function getPaymentDescription(?Payment $payment): array
    {
        if (is_null($payment)) {
            return ['', false];
        }

        $paymentDescription = json_decode($payment->processor_returned_response_description, true);
        $isJsonDescription = !is_null($paymentDescription);

        if (is_array($paymentDescription)) {
            $paymentDescription = collect($paymentDescription)
                ->mapWithKeys(function ($item, $key) {
                    $humanReadableKey = Str::of($key)
                        ->snake()
                        ->title()
                        ->replace("_", " ")
                        ->__toString();

                    return [$humanReadableKey => $item];
                })
                ->toArray();
        }

        return [$paymentDescription, $isJsonDescription];
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::reQuery()
     */
    public function reQueryUnsuccessfulPayment(Payment $unsuccessfulPayment): ?ReQuery
    {
        /** @var PaymentHandlerInterface **/
        $handler = new (PaymentService::getHandlerFqcn($unsuccessfulPayment->payment_processor_name));

        $reQueryResponse = app(get_class($handler))->reQuery($unsuccessfulPayment);

        if ($reQueryResponse == null) {
            return null;
        }

        $isSuccessful = boolval($reQueryResponse->payment->refresh()->is_success);

        if ($isSuccessful) {
            event(new SuccessfulLaravelMultipayPaymentEvent($reQueryResponse->payment));
        }

        return $reQueryResponse;
    }

    public function paymentCompletionWebhookHandler(Request $request)
    {
        $payment = $this->processWebhook($request);

        return $payment ? self::WEBHOOK_OKAY : null;
    }

    /**
     * @return ?Payment
     */
    protected function processWebhook(Request $request): ?Payment
    {
        /** @var ?Payment */
        $payment = collect(self::getNamesOfPaymentHandlers())
            ->map(function (string $paymentHandlerName) use ($request) {
                $paymentHandler = (new PaymentService())->getPaymentHandlerByName($paymentHandlerName);

                try {
                    $payment = $paymentHandler->handleExternalWebhookRequest($request);

                    if ($payment->is_success) {
                        event(new SuccessfulLaravelMultipayPaymentEvent($payment));
                    }

                    return $payment;
                } catch (UnknownWebhookException $exception) {
                }
            })
            ->filter()
            ->first();

        return $payment;
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
            'update_model_success' => 'required:',
            'update_model_unique_column' => 'required_with:update_model_success',
            'update_model_unique_column_value' => 'required_with:update_model_unique_column',
        ];

        $optionalRequestForEloquentModelLinkage->validate(array_keys($validationEntries));

        $model = (new $optionalRequestForEloquentModelLinkage->update_model_success())
            ->where($optionalRequestForEloquentModelLinkage->update_model_unique_column, $optionalRequestForEloquentModelLinkage->update_model_unique_column_value)
            ->first();

        if ($model) {
            $model->update(['payment_id' => $payment->id]);
        }
    }

    public static function getUniquePaymentHandlerName(): string
    {
        return Str::of(static::class)->afterLast("\\");
    }

    public function paymentIsUnsettled(Payment $payment): bool
    {
        throw new \Exception(static::class . " does not support checking if payment is unsettled");
    }

    public function resumeUnsettledPayment(Payment $payment): mixed
    {
        throw new \Exception(static::class . " cannot resume previous payment session");
    }

    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string
    {
        throw new \Exception(static::class . " does not support creating payment plan");
    }

    public function subscribeToPlan(User $user, PaymentPlan $plan, string $transactionReference): string
    {
        throw new \Exception(static::class . " does not support subscribing to payment plan");
    }
}
