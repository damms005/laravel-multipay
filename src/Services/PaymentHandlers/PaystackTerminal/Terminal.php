<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers\PaystackTerminal;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Customer;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;

class Terminal
{
    /**
     * Create a payment request.
     *
     * @param string $model The name of the model that has the email address
     * @param  integer $modelId The model id
     * @param  string  $description
     * @param  integer $amount Amount in kobo
     *
     * @return Payment
     */
    public function createPaymentRequest(string $model, int $modelId, string $email, string $description, int $amount): Payment
    {
        $customer = Customer::where('model', $model)->where('model_id', $modelId)->first();
        $ref = Str::uuid()->toString();

        if (!$customer) {
            $customer = $this->createCustomer($model, $modelId, $email);
        }

        $payload = [
            'customer' => $customer->customer_id,
            'description' => $description,
            'line_items' => [
                ['name' => $description, 'amount' => $amount, 'quantity' => 1],
            ],
            'metadata' => [
                'reference' => $ref,
            ]
        ];

        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->post('https://api.paystack.co/paymentrequest', $payload)
            ->json();

        if (!$response['status']) {
            throw new \Exception("Could not create payment request. " . json_encode($response));
        }

        if (!Arr::get($response, 'data.id', false) || !Arr::get($response, 'data.offline_reference', false)) {
            throw new \Exception("Could not create payment request. Response id and offline reference are both needed. Received: " . json_encode($response));
        }

        $metadata = [
            'customer_id' => $customer->id,
            'response' => $response,
        ];

        $payment = Payment::create([
            'original_amount_displayed_to_user' => $amount,
            'transaction_currency' => $response['data']['currency'],
            'transaction_description' => $description,
            'transaction_reference' => $ref,
            'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
            'metadata' => $metadata,
        ]);

        return $payment;
    }

    public function waitForTerminalHardware()
    {
        $terminalId = config("laravel-multipay.paystack_terminal_id");

        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->get("https://api.paystack.co/terminal/{$terminalId}/presence");

        $responseJson = $response->json();

        if (!Arr::get($responseJson, 'data.online', false) || Arr::get($responseJson, 'data.available', false)) {
            throw new \Exception("Terminal hardware error: " . $response->body());
        }
    }

    /**
     * @return string The event id. It is useful for tracking the event.
     *
     * @see https://paystack.com/docs/terminal/push-payment-requests/#verify-event-delivery
     */
    public function pushToTerminal(Payment $payment): string
    {
        $terminalId = config("laravel-multipay.paystack_terminal_id");
        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->post("https://api.paystack.co/terminal/{$terminalId}/event", [
                'type' => 'invoice',
                'action' => 'process',
                'data' => [
                    'id' => $payment->metadata['response']['data']['id'],
                    'reference' => $payment->metadata['response']['data']['offline_reference'],
                ],
            ]);

        $responseJson = $response->json();

        if (is_null($responseJson)) {
            throw new \Exception("Could not push to terminal. " . $response->body());
        }

        if (!$responseJson['status']) {
            throw new \Exception("Could not push to terminal. " . json_encode($responseJson));
        }

        return $responseJson['data']['id'];
    }

    /**
     * You can only confirm that a device received an event within 48 hours from the request initiation
     *
     * @see https://paystack.com/docs/terminal/push-payment-requests/#verify-event-delivery
     */
    public function terminalReceivedPaymentRequest(string $paymentEventId): bool
    {
        $terminalId = config("laravel-multipay.paystack_terminal_id");
        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->get("curl https://api.paystack.co/terminal/{$terminalId}/event/{$paymentEventId}")
            ->json();

        if (!$response['status']) {
            throw new \Exception("Could not push to terminal. " . json_encode($response));
        }

        return $response['data']['id'];
    }

    /**
     * Create a customer.
     *
     * @param  integer $modelId The user id
     *
     * @return array
     */
    protected function createCustomer(string $model, int $modelId, string $email): Customer
    {
        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->post('https://api.paystack.co/customer', ['email' => $email])
            ->json();

        if (!$response['status']) {
            throw new \Exception("Could not create customer. " . json_encode($response));
        }

        $customer = Customer::create([
            'model' => $model,
            'model_id' => $modelId,
            'customer_id' => $response['data']['customer_code'],
        ]);

        return $customer;
    }
}
