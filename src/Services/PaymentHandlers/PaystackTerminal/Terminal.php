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
     * @param  integer $user The user id
     * @param  string  $description
     * @param  integer $amount Amount in kobo
     *
     * @return Payment
     */
    public function createPaymentRequest(int $user, string $email, string $description, int $amount): Payment
    {
        $customer = Customer::where('user_id', $user)->first();

        if (!$customer) {
            $customer = $this->createCustomer($user, $email);
        }

        $payload = [
            'customer' => $customer->customer_id,
            'description' => $description,
            'line_items' => [
                ['name' => $description, 'amount' => $amount, 'quantity' => 1],
            ],
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

        $payment = Payment::create([
            'user_id' => $user,
            'original_amount_displayed_to_user' => $amount,
            'transaction_currency' => $response['data']['currency'],
            'transaction_description' => $description,
            'transaction_reference' => Str::random(),
            'payment_processor_name' => Paystack::getUniquePaymentHandlerName(),
            'metadata' => json_encode($response),
        ]);

        return $payment;
    }

    public function terminalIsReady()
    {
        $terminalId = config("laravel-multipay.paystack_terminal_id");

        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->get("https://api.paystack.co/terminal/{$terminalId}/presence")
            ->json();

        return Arr::get($response, 'data.online', false) && Arr::get($response, 'data.available', false);
    }

    /**
     * Create a customer.
     *
     * @param  integer $user The user id
     *
     * @return array
     */
    protected function createCustomer(int $user, string $email): Customer
    {
        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->post('https://api.paystack.co/customer', ['email' => $email])
            ->json();

        if (!$response['status']) {
            throw new \Exception("Could not create customer. " . json_encode($response));
        }

        $customer = Customer::create([
            'user_id' => $user,
            'customer_id' => $response['data']['customer_code'],
        ]);

        return $customer;
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
                    'id' => $payment->metadata['data']['id'],
                    'reference' => $payment->metadata['data']['offline_reference'],
                ],
            ])
            ->json();

        if (!$response['status']) {
            throw new \Exception("Could not push to terminal. " . json_encode($response));
        }

        return $response['data']['id'];
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
}
