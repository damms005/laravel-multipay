<?php
return [

	/**
	 * String to pre-pend to database table names
	 */
	"table_prefix"        => "",

	/**
	 * Path name under which the routes of this package will be defined
	 */
	"payment_route_path"  => "/payment",

	"paystack_secret_key" => env('PAYSTACK_SECRET_KEY'),
];
