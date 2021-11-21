<?php

use Damms005\LaravelCashier\Services\PaymentHandlers\Paystack;

return [

    /**
     * The layout to extend when displaying views
     */
    "extended_layout"         => "layouts.app",

    /**
     * String to pre-pend to database table names
     */
    "table_prefix"            => "",

    /**
     * Path name under which the routes of this package will be defined
     */
    "payment_route_path"      => "/payment",

    "paystack_secret_key"     => env('PAYSTACK_SECRET_KEY'),

    "default_payment_handler_fqcn" => Paystack::class,

    //https://remitademo.net/remita
    "remita_base_request_url" => env('REMITA_BASE_REQUEST_URL', "https://login.remita.net/remita"),
    "remita_merchant_id"      => env('REMITA_MERCHANT_ID'),
    "remita_api_key"          => env('REMITA_API_KEY'),

    /**
     * All your Remita service type ids should be defined here.
     * For Remita, the snake_case of your payment description
     * should always be equal to a Remita service type id.
     * Hence, the values in this array should be a key-value pair of
     * the snake_case of your payment description and the corresponding
     * Remita service type id
     */
    "remita_service_types"    => [
    ],
];
