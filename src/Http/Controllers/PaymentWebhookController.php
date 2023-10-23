<?php

namespace Damms005\LaravelMultipay\Http\Controllers;

use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        /** @var BasePaymentHandler */
        $handler = app(BasePaymentHandler::class);

        return $handler->paymentCompletionWebhookHandler($request);
    }
}
