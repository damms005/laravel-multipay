<?php

namespace Damms005\LaravelMultipay\ValueObjects;

class MandateRequest
{
    public function __construct(
        public string $reference,
        public string $payerName,
        public string $payerEmail,
        public string $accountNumber,
        public string $bankCode,
        public string $narration,
        public string $amount,
        public string $startDate,
        public ?string $endDate = null,
        public ?string $payerPhone = null,
        public ?string $payerAddress = null,
    ) {}

    public function toMonnifyPayload(string $contractCode): array
    {
        return array_filter([
            'mandateReference' => $this->reference,
            'contractCode' => $contractCode,
            'customerName' => $this->payerName,
            'customerEmail' => $this->payerEmail,
            'customerPhoneNumber' => $this->payerPhone,
            'customerAddress' => $this->payerAddress,
            'accountNumber' => $this->accountNumber,
            'bankCode' => $this->bankCode,
            'narration' => $this->narration,
            'amount' => $this->amount,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
