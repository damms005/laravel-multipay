<?php

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

it('exits with error when no payload packager is configured', function () {
    config()->set('laravel-multipay.webhook.url', 'https://example.com');
    config()->set('laravel-multipay.webhook.signing_secret', 'secret');
    config()->set('laravel-multipay.webhook.payload_packager', null);

    $this->artisan('multipay:send-payments-webhook')
        ->expectsOutputToContain('No webhook payload packager configured')
        ->assertExitCode(1);
})->skip(
    ! class_exists(\Spatie\WebhookServer\WebhookCall::class),
    'spatie/laravel-webhook-server is not installed'
);
