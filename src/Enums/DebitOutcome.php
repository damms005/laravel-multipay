<?php

namespace Damms005\LaravelMultipay\Enums;

enum DebitOutcome: string
{
    case Succeeded = 'succeeded';

    case Pending = 'pending';

    case InsufficientFunds = 'insufficient_funds';

    case ProviderUnavailable = 'provider_unavailable';

    case InstrumentDead = 'instrument_dead';

    case MandateDead = 'mandate_dead';

    case Unknown = 'unknown';

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::InsufficientFunds, self::ProviderUnavailable, self::Unknown], true);
    }

    public function requiresNewInstrument(): bool
    {
        return in_array($this, [self::InstrumentDead, self::MandateDead], true);
    }

    public function retryAfterHours(): ?int
    {
        return match ($this) {
            self::InsufficientFunds => 6,
            self::ProviderUnavailable => 2,
            self::Unknown => 4,
            default => null,
        };
    }
}
