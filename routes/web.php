<?php

use Damms005\LaravelCashier\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::group([
	'middleware' => 'web',
	'prefix'     => config('laravel-cashier.payment_route_path'),
], function () {
	Route::group(['middleware' => ['auth']], function () {
		Route::post('/details/confirm', [PaymentController::class, 'confirm'])->name('payment.show_transaction_details_for_user_confirmation');
		Route::post('/gateway/process', [PaymentController::class, 'sendToPaymentGateway'])->name('payment.confirmation.submit');
	});

	//take it for a spin
	Route::get('/test-drive', function () {
		return view('laravel-cashier:test-drive.pay');
	})->name('payment.test-drive');
});
