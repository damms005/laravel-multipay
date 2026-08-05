<?php

namespace Damms005\LaravelMultipay\ValueObjects;

use Damms005\LaravelMultipay\Enums\DebitOutcome;

class DebitResult
{
    public function __construct(
        public DebitOutcome $outcome,
        public ?string $transactionReference = null,
        public ?string $paymentReference = null,
        public ?string $amountPaid = null,
        public ?string $currency = null,
        public ?string $paidOn = null,
        public ?string $providerMessage = null,
        public ?string $providerCode = null,
        public ?string $storedInstrumentToken = null,
        public ?array $raw = null,
    ) {}

    public static function fromMonnify(array $response): self
    {
        $body = $response['responseBody'] ?? [];
        $message = $response['responseMessage'] ?? null;
        $code = $response['responseCode'] ?? null;
        $paymentStatus = strtoupper((string) ($body['paymentStatus'] ?? ''));

        return new self(
            outcome: self::classify($response, $paymentStatus),
            transactionReference: $body['transactionReference'] ?? null,
            paymentReference: $body['paymentReference'] ?? null,
            amountPaid: isset($body['amountPaid']) ? (string) $body['amountPaid'] : null,
            currency: $body['currency'] ?? null,
            paidOn: $body['paidOn'] ?? null,
            providerMessage: is_string($message) ? $message : null,
            providerCode: $code === null ? null : (string) $code,
            storedInstrumentToken: $body['cardDetails']['cardToken'] ?? null,
            raw: $response,
        );
    }

    protected static function classify(array $response, string $paymentStatus): DebitOutcome
    {
        if ($paymentStatus === 'PAID') {
            return DebitOutcome::Succeeded;
        }

        if (in_array($paymentStatus, ['PENDING', 'PROCESSING', 'IN_PROGRESS'], true)) {
            return DebitOutcome::Pending;
        }

        $haystack = strtolower(trim(
            ((string) ($response['responseMessage'] ?? ''))
            . ' '
            . ((string) ($response['responseBody']['paymentStatus'] ?? ''))
            . ' '
            . ((string) ($response['responseBody']['responseMessage'] ?? ''))
        ));

        $families = [
            DebitOutcome::InsufficientFunds->value => ['insufficient', 'not sufficient', 'no sufficient', 'low balance', 'inadequate fund'],
            DebitOutcome::MandateDead->value => ['mandate', 'no debit', 'not authorised', 'not authorized', 'consent', 'limit exceeded', 'restricted'],
            DebitOutcome::InstrumentDead->value => ['expired', 'invalid card', 'card not', 'delisted', 'stolen', 'lost card', 'do not honour', 'do not honor'],
            DebitOutcome::ProviderUnavailable->value => ['timeout', 'timed out', 'unavailable', 'downtime', 'try again', 'system error', 'switch'],
        ];

        foreach ($families as $outcome => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return DebitOutcome::from($outcome);
                }
            }
        }

        return DebitOutcome::Unknown;
    }

    public function isSuccessful(): bool
    {
        return $this->outcome->isSuccessful();
    }
}
