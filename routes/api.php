<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

//we are using 'api' route for payment completion callback because some payment providers
//post to the URL
Route::group([
	'middleware' => 'api',
	'prefix'     => config('laravel-casher.payment_route_path'),
], function () {
	Route::match(['get', 'post'], '/completed', [PaymentController::class, 'handlePaymentGatewayResponse'])->name('payment.finished.callback_url');
});
