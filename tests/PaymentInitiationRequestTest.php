<?php

beforeEach(function () {
    $this->withExceptionHandling();
});

it('passes when only user_id is provided', function () {
    $response = post($this, ['user_id' => 1]);

    $response->assertStatus(200);
});

it('fails when only payer_name is provided', function () {
    $response = post($this, ['payer_name' => 'John Doe']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['payer_email', 'payer_phone']);
});

it('fails when only payer_email is provided', function () {
    $response = post($this, ['payer_email' => 'foo@bar.com']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['payer_name', 'payer_phone']);
});

it('fails when only payer_phone is provided', function () {
    $response = post($this, ['payer_phone' => '1234567890']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['payer_name', 'payer_email']);
});

it('fails when payer_name, payer_email are provided without user_id', function () {
    $response = post($this, [
        'payer_name' => 'John Doe',
        'payer_email' => 'johndoe@example.com'
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['payer_phone']);
});

it('fails when payer_name, payer_phone are provided without user_id', function () {
    $response = post($this, [
        'payer_name' => 'John Doe',
        'payer_phone' => '1234567890'
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['payer_email']);
});

it('fails when payer_email, payer_phone are provided without user_id', function () {
    $response = post($this, [
        'payer_email' => 'foo@bar.com',
        'payer_phone' => '1234567890'
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['payer_name']);
});

it('passes when all fields are provided', function () {
    $response = post($this, [
        'user_id' => 1,
        'payer_name' => 'John Doe',
        'payer_email' => 'johndoe@example.com',
        'payer_phone' => '1234567890'
    ]);

    $response->assertStatus(200);
});

function post($app, array $data)
{
    return $app->postJson(
        route('payment.show_transaction_details_for_user_confirmation'),
        array_merge([
            'amount' => 1000,
            'currency' => 'NGN',
            'transaction_description' => 'Test transaction',
            'metadata' => json_encode(['foo' => 'bar']),
            'payment_processor' => 'Paystack',
        ], $data)
    );
}
