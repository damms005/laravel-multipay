<?php

return [

    /**
     * FQCN of model that your app uses for authentication
     */
    'user_model_fqcn' => App\Models\User::class,

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

    /**
     * All your Remita service type ids should be defined here.
     * For Remita, the snake_case of your payment description
     * should always be equal to a Remita service type id.
     * Hence, the values in this array should be a key-value pair of
     * the snake_case of your payment description and the corresponding
     * Remita service type id
     */
    'remita_service_types' => [],

    'payment_confirmation_notice' => env(
        'PAYMENT_CONFIRMATION_NOTICE',
        'The details of your transaction is given below. Kindly print this page first before proceeding to click on Pay Now (this ensures that you have your transaction reference in case you need to refer to this transaction in the future).'
    ),

    'enable_payment_confirmation_page_print' => env('ENABLE_PAYMENT_CONFIRMATION_PAGE_PRINT', true),
];
