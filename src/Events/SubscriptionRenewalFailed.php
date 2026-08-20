<?php

namespace Damms005\LaravelMultipay\Events;

use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionRenewalFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ?Payment $payment,
        public Subscription $subscription,
        public array $rawPayload,
    ) {}
}
