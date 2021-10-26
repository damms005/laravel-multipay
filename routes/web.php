<?php

use Damms005\LaravelCashier\Http\Controllers\PaymentController;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => config('laravel-cashier.payment_route_path', 'payment')], function () {
	Route::group(['middleware' => ['web']], function () {
		Route::post('/details/confirm', [PaymentController::class, 'confirm'])->name('payment.show_transaction_details_for_user_confirmation');
		Route::post('/gateway/process', [PaymentController::class, 'sendToPaymentGateway'])->name('payment.confirmation.submit');

		//take it for a spin
		Route::get('/test-drive', function () {
			throw_if(!Auth::check(), "Please setup authentication (e.g. with Laravel Breeze) and login before test-driving");

			$paymentProviders = BasePaymentHandler::getNamesOfPaymentHandlers();
			$user_id          = auth()->user()->id;

			return view('laravel-cashier::test-drive.pay', compact('paymentProviders', 'user_id'));
		})
		->name('payment.test-drive');
	});

	//use 'api' route for payment completion callback because some payment providers do POST rather than GET
	Route::group(['middleware' => 'api'], function () {
		Route::match(['get', 'post'], '/completed', [PaymentController::class, 'handlePaymentGatewayResponse'])
			->name('payment.finished.callback_url');
	});
});
