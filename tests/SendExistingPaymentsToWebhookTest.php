<?php

use Damms005\LaravelMultipay\Models\Payment;

it('exits with error when webhook url is not configured', function () {
    config()->set('laravel-multipay.webhook.url', null);
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');

    $this->artisan('multipay:send-payments-webhook')
        ->expectsOutputToContain('not configured')
        ->assertExitCode(1);
});

it('exits with error when signing secret is not configured', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com');
    config()->set('laravel-multipay.webhook.signing_secret', null);

    $this->artisan('multipay:send-payments-webhook')
        ->expectsOutputToContain('not configured')
        ->assertExitCode(1);
});

it('exits with error when spatie webhook server is not installed', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com');
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');

    $this->artisan('multipay:send-payments-webhook')
        ->expectsOutputToContain('not installed')
        ->assertExitCode(1);
})->skip(
    class_exists(\Spatie\WebhookServer\WebhookCall::class),
    'spatie/laravel-webhook-server is installed'
);

it('reports no payments found when none exist', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com');
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');

    $this->artisan('multipay:send-payments-webhook')
        ->expectsOutputToContain('No successful payments found')
        ->assertExitCode(0);
})->skip(
    ! class_exists(\Spatie\WebhookServer\WebhookCall::class),
    'spatie/laravel-webhook-server is not installed'
);

it('aborts when payments are missing fee_head_id in metadata', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com');
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');

    Payment::create([
        'user_id' => 1,
        'transaction_reference' => 'ref-no-feehead',
        'payment_processor_name' => 'Paystack',
        'transaction_currency' => 'NGN',
        'transaction_description' => 'Test',
        'original_amount_displayed_to_user' => 1000,
        'is_success' => true,
        'metadata' => [],
    ]);

    $this->artisan('multipay:send-payments-webhook')
        ->expectsOutputToContain('missing fee_head_id')
        ->assertExitCode(1);
})->skip(
    ! class_exists(\Spatie\WebhookServer\WebhookCall::class),
    'spatie/laravel-webhook-server is not installed'
);

it('only includes successful payments', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com');
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');

    Payment::create([
        'user_id' => 1,
        'transaction_reference' => 'ref-failed',
        'payment_processor_name' => 'Paystack',
        'transaction_currency' => 'NGN',
        'transaction_description' => 'Failed payment',
        'original_amount_displayed_to_user' => 1000,
        'is_success' => false,
        'metadata' => ['fee_head_id' => 1, 'fee_head_name' => 'Tuition'],
    ]);

    $this->artisan('multipay:send-payments-webhook')
        ->expectsOutputToContain('No successful payments found')
        ->assertExitCode(0);
})->skip(
    ! class_exists(\Spatie\WebhookServer\WebhookCall::class),
    'spatie/laravel-webhook-server is not installed'
);

it('filters payments by date range', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com');
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');

    Payment::create([
        'user_id' => 1,
        'transaction_reference' => 'ref-old',
        'payment_processor_name' => 'Paystack',
        'transaction_currency' => 'NGN',
        'transaction_description' => 'Old payment',
        'original_amount_displayed_to_user' => 1000,
        'is_success' => true,
        'created_at' => '2024-01-01 00:00:00',
        'metadata' => ['fee_head_id' => 1, 'fee_head_name' => 'Tuition'],
    ]);

    $this->artisan('multipay:send-payments-webhook --from=2025-01-01')
        ->expectsOutputToContain('No successful payments found')
        ->assertExitCode(0);
})->skip(
    ! class_exists(\Spatie\WebhookServer\WebhookCall::class),
    'spatie/laravel-webhook-server is not installed'
);
