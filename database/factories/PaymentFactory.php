<?php

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public $model = Payment::class;

    public function definition(): array
    {
        return [
            "user_id" => 1,
            "completion_url" => 'localhost',
            "transaction_reference" => 12345,
            "payment_processor_name" => $this->faker->uuid(),
            "transaction_currency" => $this->faker->currencyCode(),
            "transaction_description" => $this->faker->sentence(),
            "original_amount_displayed_to_user" => 500,
            "metadata" => [],
        ];
    }
}
