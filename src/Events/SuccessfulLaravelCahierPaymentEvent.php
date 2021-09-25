<?php

namespace Damms005\LaravelCashier\Events;

use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SuccessfulLaravelCahierPaymentEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     *
     * @var Payment
     */
    public $payment;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
