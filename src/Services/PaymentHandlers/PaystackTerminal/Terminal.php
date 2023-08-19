<?php

namespace Damms005\LaravelMultipay\Services\PaymentHandlers\PaystackTerminal;

use Illuminate\Support\Facades\Http;

class Terminal
{
    /**
     * Create a payment request.
     *
     * @param  string  $description
     * @param  integer $amount Amount in kobo
     *
     * @return void
     */
    public function createPaymentRequest(string $description, int $amount)
    {
        $payload = [
            'customer' => null,
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
}
