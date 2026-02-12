<?php

namespace Damms005\LaravelMultipay\Listeners;

use Damms005\LaravelMultipay\Events\SuccessfulLaravelMultipayPaymentEvent;
use Damms005\LaravelMultipay\Models\Payment;

class SendPaymentWebhookListener
{
    public function handle(SuccessfulLaravelMultipayPaymentEvent $event): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if (! class_exists(\Spatie\WebhookServer\WebhookCall::class)) {
            return;
        }

        $payload = self::buildPayload($event->payment);

        \Spatie\WebhookServer\WebhookCall::create()
            ->url(config('laravel-multipay.webhook.url'))
            ->payload($payload)
            ->useSecret(config('laravel-multipay.webhook.signing_secret'))
            ->dispatch();
    }

    public static function buildPayload(Payment $payment): array
    {
        return [
            'transaction_reference' => $payment->transaction_reference,
            'amount_paid' => $payment->original_amount_displayed_to_user,
            'transaction_description' => $payment->transaction_description,
            'payer_name' => self::safeGetPayerName($payment),
            'payer_email' => self::safeGetPayerEmail($payment),
            'payment_processor_name' => $payment->payment_processor_name,
            'paid_at' => $payment->processor_returned_transaction_date ?? $payment->updated_at?->toISOString(),
            'metadata' => $payment->metadata ? (array) $payment->metadata : [],
        ];
    }

    private function isConfigured(): bool
    {
        return config('laravel-multipay.webhook.url') && config('laravel-multipay.webhook.signing_secret');
    }

    private static function safeGetPayerName(Payment $payment): ?string
    {
        try {
            return $payment->getPayerName();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function safeGetPayerEmail(Payment $payment): ?string
    {
        try {
            return $payment->getPayerEmail();
        } catch (\Throwable) {
            return null;
        }
    }
}
