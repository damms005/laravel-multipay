<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;

Route::group([
	'middleware' => 'web',
	'prefix'     => config('laravel-casher.payment_route_path'),
], function () {
	Route::group(['middleware' => ['auth']], function () {
		Route::post('/details/confirm', [PaymentController::class, 'confirm'])->name('payment.show_transaction_details_for_user_confirmation');
		Route::post('/gateway/process', [PaymentController::class, 'sendToPaymentGateway'])->name('payment.confirmation.submit');
	});
});
