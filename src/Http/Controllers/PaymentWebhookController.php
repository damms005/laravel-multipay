<?php

namespace Damms005\LaravelCashier\Http\Controllers;

use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        return (new BasePaymentHandler())->paymentCompletionWebhookHandler($request);
    }
}
