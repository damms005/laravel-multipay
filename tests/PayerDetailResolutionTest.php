<?php

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('resolves payer details from user model when properties are non-empty', function () {
    DB::statement('ALTER TABLE users ADD COLUMN name TEXT');
    DB::statement('ALTER TABLE users ADD COLUMN phone TEXT');
    DB::table('users')->where('id', 1)->update([
        'name' => 'John Doe',
        'phone' => '08012345678',
    ]);

    $payment = createPayment();

    expect($payment->getPayerName())->toBe('John Doe')
        ->and($payment->getPayerEmail())->toBe('user@gmail.com')
        ->and($payment->getPayerPhone())->toBe('08012345678');
});

it('falls back to metadata when user property is empty', function () {
    $payment = createPayment();
    $payment->metadata = [
        'payer_name' => 'Meta Name',
        'payer_phone' => '09087654321',
    ];
    $payment->save();

    $fresh = $payment->fresh();

    expect($fresh->getPayerName())->toBe('Meta Name')
        ->and($fresh->getPayerEmail())->toBe('user@gmail.com')
        ->and($fresh->getPayerPhone())->toBe('09087654321');
});

it('resolves payer details from metadata when no user is associated', function () {
    $payment = Payment::create([
        'completion_url' => 'https://localhost.com',
        'transaction_reference' => Str::uuid(),
        'payment_processor_name' => Str::uuid(),
        'transaction_currency' => 'USD',
        'transaction_description' => Str::random(),
        'original_amount_displayed_to_user' => 500,
        'metadata' => [
            'payer_name' => 'No User Name',
            'payer_email' => 'nouser@example.com',
            'payer_phone' => '07011111111',
        ],
    ]);

    expect($payment->getPayerName())->toBe('No User Name')
        ->and($payment->getPayerEmail())->toBe('nouser@example.com')
        ->and($payment->getPayerPhone())->toBe('07011111111');
});

it('throws when payer detail cannot be resolved without a user', function () {
    $payment = Payment::create([
        'completion_url' => 'https://localhost.com',
        'transaction_reference' => Str::uuid(),
        'payment_processor_name' => Str::uuid(),
        'transaction_currency' => 'USD',
        'transaction_description' => Str::random(),
        'original_amount_displayed_to_user' => 500,
        'metadata' => [],
    ]);

    $payment->getPayerName();
})->throws(Exception::class, 'No user is associated with this payment');

it('throws with user context when user property is empty and metadata is missing', function () {
    $payment = createPayment();

    $payment->getPayerName();
})->throws(Exception::class, 'The user (ID: 1)');
