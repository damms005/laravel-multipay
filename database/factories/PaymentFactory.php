<?php

namespace Damms005\LaravelMultipay\Database\Factories;

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition()
    {
        return [
            'user_id' => 1,
            'original_amount_displayed_to_user' => 123,
            'transaction_currency' => 'NGN',
            'transaction_description' => 'Awesome payment',
            'transaction_reference' => '123-ABC',
            'payment_processor_name' => 'Paystack',
        ];
    }
}
