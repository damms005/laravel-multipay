<?php

namespace Damms005\LaravelMultipay\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

class PaymentService
{
    public static function storePaymentAndShowUserBeforeProcessing(int $user_id, float $amount, string $description, string $currency, string $transaction_reference, string | null $view, $metadata = null)
    {
        $basePaymentHandler = new BasePaymentHandler();

        return $basePaymentHandler->storePaymentAndShowUserBeforeProcessing($user_id, $amount, $description, $currency, $transaction_reference, null, null, $view, $metadata);
    }

    public function getPaymentHandlerByName(string $paymentHandlerName): PaymentHandlerInterface
    {
        try {
            $handlerFqcn = "\\Damms005\\LaravelMultipay\\Services\\PaymentHandlers\\{$paymentHandlerName}";

            /** @var PaymentHandlerInterface */
            $paymentHandler = new $handlerFqcn();

            return $paymentHandler;
        } catch (\Throwable $th) {
            throw new \Exception("Could not get payment processor: {$paymentHandlerName}");
        }
    }

    public function handlerGatewayResponse(Request $paymentGatewayServerResponse, string $paymentHandlerName): ?Payment
    {
        $paymentHandler = $this->getPaymentHandlerByName($paymentHandlerName);

        return $paymentHandler->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($paymentGatewayServerResponse);
    }
}
