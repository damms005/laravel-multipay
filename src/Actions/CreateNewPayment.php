<?php

namespace Damms005\LaravelMultipay\Actions;

use Damms005\LaravelMultipay\Models\Payment;

class CreateNewPayment
{
    public function execute(
        ?string $payment_processor_name,
        ?int $user_id,
        ?string $completion_url,
        ?string $transaction_reference,
        ?string $currency,
        ?string $transaction_description,
        ?string $original_amount_displayed_to_user,
        ?array $metadata
    ): Payment {
        return Payment::firstOrCreate([
            "user_id" => $user_id,
            "completion_url" => $completion_url,
            "transaction_reference" => $transaction_reference,
            "payment_processor_name" => $payment_processor_name,
            "transaction_currency" => $currency,
            "transaction_description" => $transaction_description,
            "original_amount_displayed_to_user" => $original_amount_displayed_to_user,
            "metadata" => $metadata,
        ]);
    }
}
