<?php

namespace Damms005\LaravelMultipay\Actions;

use Damms005\LaravelMultipay\Models\Payment;

class CreateNewPayment
{
    public function execute(
        ?string $payment_processor_name,
        string | int $user_id,
        ?string $completion_url,
        ?string $transaction_reference,
        ?string $currency,
        ?string $transaction_description,
        ?string $original_amount_displayed_to_user,
        ?string $metadata
    ): Payment {
        return Payment::firstOrCreate([
            "user_id" => $user_id,
            "completion_url" => $completion_url,
            "transaction_reference" => $transaction_reference,
            "payment_processor_name" => $payment_processor_name,
            "transaction_currency" => $currency,
            "transaction_description" => $transaction_description,
            "original_amount_displayed_to_user" => $original_amount_displayed_to_user,
            "metadata" => $this->formatMetadata($metadata),
        ]);
    }

    /**
     * The metadata column is cast as AsArrayObject. Hence, we need to ensure that any
     * value saved is not a string, else we risk getting a doubly-encoded string in db
     * as an effect of the AsArrayObject db casting
     *
     * @param mixed $metadata
     *
     */
    public function formatMetadata(mixed $metadata): array|null
    {
        if (empty($metadata)) {
            return null;
        }

        if (!is_string($metadata)) {
            return null;
        }

        return json_decode($metadata, true);
    }
}
