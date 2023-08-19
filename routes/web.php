<?php

use Damms005\LaravelMultipay\Http\Controllers\PaymentController;
use Damms005\LaravelMultipay\Http\Controllers\PaymentWebhookController;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => config('laravel-multipay.payment_route_path', 'payment')], function () {

    Route::group(['middleware' => ['web']], function () {
        Route::post('/details/confirm', [PaymentController::class, 'confirm'])->name('payment.show_transaction_details_for_user_confirmation');
        Route::post('/gateway/process', [PaymentController::class, 'sendToPaymentGateway'])->name('payment.confirmation.submit');

        // Let's take it for a spin!
        Route::get('/test-drive', function () {
            throw_if(!Auth::check(), "Please setup authentication (e.g. with Laravel Breeze) and login before test-driving");

            $userId          = auth()->user()->id;
            $paymentProviders = BasePaymentHandler::getNamesOfPaymentHandlers();

            return view('laravel-multipay::test-drive.pay', [
                'userId' => $userId,
                'providers' => $paymentProviders,
            ]);
        })
            ->name('payment.test-drive');
    });

    // Use 'api' route for payment completion callback because some payment providers do POST rather than GET
    Route::middleware('api')
        ->group(function () {

            // Route that users get redirected to when done with payment
            Route::match(['get', 'post'], '/completed', [PaymentController::class, 'handlePaymentGatewayResponse'])
                ->name('payment.finished.callback_url');

            Route::match(['get', 'post'], '/completed/notify', PaymentWebhookController::class)
                ->name('payment.external-webhook-endpoint');
        });
});
