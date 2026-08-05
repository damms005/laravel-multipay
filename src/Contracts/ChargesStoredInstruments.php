<?php

namespace Damms005\LaravelMultipay\Contracts;

use Damms005\LaravelMultipay\ValueObjects\DebitResult;

interface ChargesStoredInstruments
{
    public function extractStoredInstrumentToken(string $transactionReference): ?string;

    public function chargeStoredInstrument(
        string $token,
        string $payerEmailAtTokenization,
        string $amount,
        string $paymentReference,
        string $narration,
        string $currency = 'NGN',
    ): DebitResult;
}
