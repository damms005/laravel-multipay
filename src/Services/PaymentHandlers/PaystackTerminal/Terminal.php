<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers\PaystackTerminal;

use Illuminate\Support\Facades\Http;
use Damms005\LaravelMultipay\Models\Customer;

class Terminal
{
    /**
     * Create a payment request.
     *
     * @param  integer $user The user id
     * @param  string  $description
     * @param  integer $amount Amount in kobo
     *
     * @return void
     */
    public function createPaymentRequest(int $user, string $email, string $description, int $amount)
    {
        $customer = Customer::where('user_id', $user)->first();

        if (!$customer) {
            $customer = $this->createCustomer($user, $email);
        }

        $payload = [
            'customer' => $customer,
            'description' => $description,
            'line_items' => [
                ['name' => $description, 'amount' => $amount, 'quantity' => 1],
            ],
        ];

        $response = Http::acceptJson()
            ->withToken(config("laravel-multipay.paystack_secret_key"))
            ->post('https://api.paystack.co/paymentrequest', $payload)
            ->json();

        dump($response);
    }

    /**
     * Create a customer.
     *
     * @param  integer $user The user id
     *
     * @return array
     */
    public function createCustomer(int $user, string $email): Customer
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
}
