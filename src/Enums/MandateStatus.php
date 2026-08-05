<?php

namespace Damms005\LaravelMultipay\Enums;

enum MandateStatus: string
{
    case Pending = 'PENDING';

    case PendingAuthorization = 'PENDING_AUTHORIZATION';

    case PendingActivation = 'PENDING_ACTIVATION';

    case Activated = 'ACTIVATED';

    case AuthorizationExpired = 'AUTHORIZATION_EXPIRED';

    case Expired = 'EXPIRED';

    case Cancelled = 'CANCELLED';

    case Suspended = 'SUSPENDED';

    public static function fromProviderValue(?string $value): self
    {
        return self::tryFrom(strtoupper(trim((string) $value))) ?? self::Pending;
    }

    public function isDebitable(): bool
    {
        return $this === self::Activated;
    }

    public function isAwaitingPayer(): bool
    {
        return in_array($this, [self::Pending, self::PendingAuthorization, self::PendingActivation], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::AuthorizationExpired,
            self::Expired,
            self::Cancelled,
            self::Suspended,
        ], true);
    }

    public function describe(): string
    {
        return match ($this) {
            self::Pending => 'Mandate created but not yet sent to the payer.',
            self::PendingAuthorization => 'Awaiting the payer to authorise the mandate.',
            self::PendingActivation => 'Authorised by the payer, awaiting activation by the bank.',
            self::Activated => 'Active and available for debiting.',
            self::AuthorizationExpired => 'The payer did not authorise within the allowed window.',
            self::Expired => 'The mandate reached its end date.',
            self::Cancelled => 'The mandate was cancelled.',
            self::Suspended => 'The mandate was suspended by the bank or the payer.',
        };
    }
}
