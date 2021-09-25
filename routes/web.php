<?php

use Illuminate\Support\Facades\Route;
use Damms005\LaravelCashier\Http\Controllers\PaymentController;

Route::group([
	'middleware' => 'web',
	'prefix'     => config('laravel-cashier.payment_route_path'),
], function () {
	Route::group(['middleware' => ['auth']], function () {
		Route::post('/details/confirm', [PaymentController::class, 'confirm'])->name('payment.show_transaction_details_for_user_confirmation');
		Route::post('/gateway/process', [PaymentController::class, 'sendToPaymentGateway'])->name('payment.confirmation.submit');

		//take for a spin
		Route::post('/test-drive', [PaymentController::class, 'confirm'])->name('payment.test-drive');
	});
});
