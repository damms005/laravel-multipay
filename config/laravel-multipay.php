<?php

return [

    /**
     * It is sometimes needed to get the payer's name, email or phone number.
     * LaravelMultipay associates you User model to the Payment model, such
     * that the payer's name, email and phone number can be gotten from
     * the User model if the user model is associated with the payment,
     * either as direct column names or Eloquent model accessors.
     * For your app, it may be Customer model, Student model, etc.
     */
    'user_model_fqcn' => App\Models\User::class,

    /**
     * For 'user_model_fqcn' above, provide the name of the column
     * that corresponds to the user model's primary key
     */
    'user_model_owner_key' => 'id',

    /**
     * For 'user_model_fqcn' above, provide the names of the model properties
     * that correspond to the payer's name, email and phone number.
     * These can be direct column names or Eloquent model accessors.
     */
    'user_model_properties' => [
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
    ],

    /**
     * The layout to extend when displaying views
     */
    'extended_layout'         => 'layouts.app',

    /**
     * In the layout extended, provide the name of the section
     * that yields the content
     */
    'section_name'         => 'content',

    /**
     * String to pre-pend to database table names
     */
    'table_prefix'            => '',

    /**
     * Path name under which the routes of this package will be defined
     */
    'payment_route_path'      => '/payment',

    'paystack_secret_key'     => env('PAYSTACK_SECRET_KEY'),

    'default_payment_handler_fqcn' => Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack::class,

    //https://remitademo.net/remita
    'remita_base_request_url' => env('REMITA_BASE_REQUEST_URL', 'https://login.remita.net/remita'),
    'remita_merchant_id'      => env('REMITA_MERCHANT_ID'),
    'remita_api_key'          => env('REMITA_API_KEY'),

    'payment_confirmation_notice' => env(
        'PAYMENT_CONFIRMATION_NOTICE',
        'The details of your transaction is given below. Kindly print this page first before proceeding to click on Pay Now (this ensures that you have your transaction reference in case you need to refer to this transaction in the future).'
    ),

    'enable_payment_confirmation_page_print' => env('ENABLE_PAYMENT_CONFIRMATION_PAGE_PRINT', true),

    'flutterwave' => [
        'publicKey'  => env('FLW_PUBLIC_KEY'),
        'secretKey'  => env('FLW_SECRET_KEY'),
        'secretHash' => env('FLW_SECRET_HASH'),
    ],

    'middleware' => [
    ],

    'webhook' => [
        'url' => env('LARAVEL_MULTIPAY_WEBHOOK_URL'),
        'signing_secret' => env('LARAVEL_MULTIPAY_WEBHOOK_SIGNING_SECRET'),
        'payload_packager' => null,
    ],
];
