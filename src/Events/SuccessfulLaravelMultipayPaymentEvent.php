<?php

namespace Damms005\LaravelMultipay\Events;

use Damms005\LaravelMultipay\Enums\ChargeKind;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Models\Subscription;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SuccessfulLaravelMultipayPaymentEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Payment $payment,
        public ChargeKind $kind = ChargeKind::OneOff,
        public ?Subscription $subscription = null,
        public array $rawPayload = [],
    ) {}

    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
