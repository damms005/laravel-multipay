<?php

namespace Damms005\LaravelMultipay\ValueObjects;

use Damms005\LaravelMultipay\Enums\MandateStatus;

class Mandate
{
    public function __construct(
        public string $reference,
        public MandateStatus $status,
        public ?string $authorizationUrl = null,
        public ?string $accountName = null,
        public ?string $accountNumber = null,
        public ?string $bankCode = null,
        public ?string $customerEmail = null,
        public ?string $startDate = null,
        public ?string $endDate = null,
        public ?string $amount = null,
        public ?array $raw = null,
    ) {}

    public static function fromMonnify(array $response, ?string $fallbackReference = null): self
    {
        $body = $response['responseBody'] ?? $response;

        $reference = $body['mandateReference']
            ?? $body['mandateCode']
            ?? $fallbackReference
            ?? '';

        return new self(
            reference: (string) $reference,
            status: MandateStatus::fromProviderValue($body['mandateStatus'] ?? ($body['status'] ?? null)),
            authorizationUrl: $body['authorizationUrl'] ?? ($body['mandateAuthorizationUrl'] ?? null),
            accountName: $body['accountName'] ?? null,
            accountNumber: $body['accountNumber'] ?? null,
            bankCode: $body['bankCode'] ?? null,
            customerEmail: $body['customerEmail'] ?? ($body['email'] ?? null),
            startDate: $body['startDate'] ?? null,
            endDate: $body['endDate'] ?? null,
            amount: isset($body['amount']) ? (string) $body['amount'] : null,
            raw: $response,
        );
    }

    public function isDebitable(): bool
    {
        return $this->status->isDebitable();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isAwaitingPayer(): bool
    {
        return $this->status->isAwaitingPayer();
    }
}
