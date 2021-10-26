<?php
return [

	/**
	 * The layout to extend when displaying views
	 */
	"extended_layout"     => "layouts.app",

	/**
	 * String to pre-pend to database table names
	 */
	"table_prefix"        => "",

	/**
	 * Path name under which the routes of this package will be defined
	 */
	"payment_route_path"  => "/payment",

	"paystack_secret_key" => env('PAYSTACK_SECRET_KEY'),

	"remita_merchant_id"  => env('REMITA_MERCHANT_ID'),
	"remita_api_key"      => env('REMITA_API_KEY'),
];
