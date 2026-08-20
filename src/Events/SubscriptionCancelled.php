<?php

namespace Damms005\LaravelMultipay\Events;

use Damms005\LaravelMultipay\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public array $rawPayload,
    ) {}
}
