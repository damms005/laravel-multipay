<?php

namespace Damms005\LaravelMultipay\Listeners;

use Damms005\LaravelMultipay\Contracts\WebhookPayloadPackager;
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

        if ($payload === null) {
            return;
        }

        \Spatie\WebhookServer\WebhookCall::create()
            ->url(config('laravel-multipay.webhook.url'))
            ->payload($payload)
            ->useSecret(config('laravel-multipay.webhook.signing_secret'))
            ->dispatch();
    }

    public static function buildPayload(Payment $payment): ?array
    {
        $packager = self::resolvePackager();

        if (! $packager) {
            return null;
        }

        return $packager->getWebhookPayload($payment);
    }

    public static function resolvePackager(): ?WebhookPayloadPackager
    {
        $packagerClass = config('laravel-multipay.webhook.payload_packager');

        if (! $packagerClass || ! class_exists($packagerClass)) {
            return null;
        }

        $packager = app($packagerClass);

        return $packager instanceof WebhookPayloadPackager ? $packager : null;
    }

    private function isConfigured(): bool
    {
        return config('laravel-multipay.webhook.url') && config('laravel-multipay.webhook.signing_secret');
    }
}
