<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers;

use Carbon\Carbon;
use Flutterwave\Payload;
use Illuminate\Http\Request;
use Flutterwave\Helper\Config;
use Flutterwave\Service\PaymentPlan;
use Illuminate\Foundation\Auth\User;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use KingFlamez\Rave\Facades\Rave as FlutterwaveRave;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Exceptions\UnknownWebhookException;
use Damms005\LaravelMultipay\Models\PaymentPlan as PaymentPlanModel;
use stdClass;

class Flutterwave extends BasePaymentHandler implements PaymentHandlerInterface
{
    public function __construct()
    {
        parent::__construct();

        // Copy config values for use by Rave package
        config()->set('flutterwave.publicKey', config('laravel-multipay.flutterwave.publicKey'));
        config()->set('flutterwave.secretKey', config('laravel-multipay.flutterwave.secretKey'));
        config()->set('flutterwave.secretHash', config('laravel-multipay.flutterwave.secretHash'));
        config()->set('flutterwave.encryptionKey' . config('laravel-multipay.flutterwave.env', ''));
    }

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
        $transactionReference = strtoupper(FlutterwaveRave::generateReference());

        // Enter the details of the payment
        $data = [
            'payment_options' => 'card',
            'amount' => $payment->original_amount_displayed_to_user,
            'email' => $payment->getPayerEmail(),
            'tx_ref' => $transactionReference,
            'currency' => "NGN",
            'redirect_url' => $redirect_or_callback_url,
            'customer' => [
                'email' => $payment->getPayerEmail(),
                "phone_number" => null,
                "name" => $payment->getPayerName(),
            ],

            "customizations" => [
                "title" => 'Application fee payment',
                "description" => "Application fee payment",
            ],
        ];

        $paymentInitialization = FlutterwaveRave::initializePayment($data);

        throw_if($paymentInitialization['status'] !== 'success', "Cannot initialize Flutterwave payment");

        $url = $paymentInitialization['data']['link'];

        $payment->transaction_reference = $transactionReference;
        $payment->save();

        header('Location: ' . $url);

        exit;
    }

    public function confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome(Request $request): ?Payment
    {
        if (!$request->has('tx_ref')) {
            return null;
        }

        $payment = $this->handleExternalWebhookRequest($request);

        return $payment;
    }

    public function reQuery(Payment $existingPayment): ?Payment
    {
        throw new \Exception("Method not yet implemented");
    }

    protected function giveValue($transactionReference, array $flutterwavePaymentDetails): ?Payment
    {
        /**
         * @var Payment
         */
        $payment = Payment::where('transaction_reference', $transactionReference)
            ->firstOrFail();

        // Ensure we have not already given value for this transaction
        if ($payment->is_success) {
            return null;
        }

        $payment->update([
            "is_success" => 1,
            "processor_returned_amount" => $flutterwavePaymentDetails['data']['amount'],
            "processor_returned_transaction_date" => new Carbon($flutterwavePaymentDetails['data']['created_at']),
            'processor_returned_response_description' => $flutterwavePaymentDetails['data']['processor_response'],
        ]);

        return $payment->fresh();
    }

    protected function getConfig(): Config
    {
        return Config::setUp(
            config('laravel-multipay.flutterwave.secretKey'),
            config('laravel-multipay.flutterwave.publicKey'),
            config('laravel-multipay.flutterwave.secretHash'),
            config('laravel-multipay.flutterwave.env', ''),
        );
    }

    public function createPaymentPlan(string $name, string $amount, string $interval, string $description, string $currency): string
    {
        $config = $this->getConfig();
        $plansService = new PaymentPlan($config);

        $payload = new Payload();
        $payload->set("amount", $amount);
        $payload->set("name", $name);
        $payload->set("interval", $interval);
        $payload->set("currency", $currency);
        $payload->set("duration", '');

        $response = $plansService->create($payload);

        return $response->data->plan_token;
    }

    public function subscribeToPlan(User $user, PaymentPlanModel $plan, string $transactionReference): string
    {
        $data = [
            'tx_ref' => $transactionReference,
            'amount' => $plan->amount,
            'currency' => $plan->currency,
            'redirect_url' => route('payment.finished.callback_url'),
            'payment_options' => 'card',
            'email' => $user->email,
            'customer' => [
                'email' => $user->email,
            ],
            'payment_plan' => $plan->payment_handler_plan_id,
        ];

        $paymentInitialization = FlutterwaveRave::initializePayment($data);

        throw_if($paymentInitialization['status'] !== 'success', "Cannot initialize Flutterwave payment");

        $url = $paymentInitialization['data']['link'];

        return $url;
    }

    /**
     * @see \Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface::handleExternalWebhookRequest
     */
    public function handleExternalWebhookRequest(Request $request): Payment
    {
        if (!$request->has('tx_ref')) {
            throw new UnknownWebhookException($this);
        }

        $transactionReference = $request->get('tx_ref');
        $payment = Payment::where('transaction_reference', $transactionReference)->firstOrFail();

        $status = $request->get('status');

        if ($status != 'successful') {
            $payment->processor_returned_response_description = $status;
            $payment->save();

            return $payment;
        }

        $transactionId = $request->get('transaction_id');
        $flutterwavePaymentDetails = FlutterwaveRave::verifyTransaction($transactionId);

        if (!$this->isValidTransaction((array)$flutterwavePaymentDetails, $payment)) {
            $payment->processor_returned_response_description = "Invalid transaction";
            $payment->save();

            return $payment;
        }

        $payment = $this->giveValue($transactionReference, (array)$flutterwavePaymentDetails);

        $this->processPaymentMetadata($payment);

        return $payment;
    }

    protected function processPaymentMetadata(Payment $payment)
    {
        if (!is_iterable($payment->metadata)) {
            return;
        }

        $isPaymentForSubscription = array_key_exists('payment_plan_id', (array)$payment->metadata);

        if (!$isPaymentForSubscription) {
            return;
        }

        $plan = PaymentPlanModel::findOrFail($payment->metadata['payment_plan_id']);

        $nextPaymentDate = match ($plan->interval) {
            'monthly' => Carbon::now()->addMonth(),
            'yearly' => Carbon::now()->addYear(),
            default => throw new \Exception("Unknown interval {$plan->interval}"),
        };

        Subscription::create([
            'user_id' => $payment->user_id,
            'payment_plan_id' => $payment->metadata['payment_plan_id'],
            'next_payment_due_date' => $nextPaymentDate,
        ]);
    }

    protected function getVerification(string $transactionId): stdClass
    {
        $transactionService = (new \Flutterwave\Service\Transactions());

        $res = $transactionService->verify($transactionId);

        return $res;
    }

    public function isValidTransaction(array $flutterwavePaymentDetails, Payment $payment)
    {
        return $flutterwavePaymentDetails['data']['amount'] == $payment->original_amount_displayed_to_user;
    }
}
