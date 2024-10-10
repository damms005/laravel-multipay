<?php

namespace Damms005\LaravelMultipay\Services;

use Illuminate\Http\Request;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;

class PaymentService
{
    public static function storePaymentAndShowUserBeforeProcessing(?int $user_id, float $amount, string $description, string $currency, string $transaction_reference, string | null $view, ?array $metadata = null, ?string $preferredProcessorUniqueName = null)
    {
        /** @var BasePaymentHandler */
        $basePaymentHandler = $preferredProcessorUniqueName
            ? self::getHandlerFqcn($preferredProcessorUniqueName)
            : app()->make(BasePaymentHandler::class);

        return $basePaymentHandler->storePaymentAndShowUserBeforeProcessing($user_id, $amount, $description, $currency, $transaction_reference, null, null, $view, $metadata);
    }

    private static function getHandlerFqcn(string $uniqueName): BasePaymentHandler
    {
        $handler = BasePaymentHandler::getNamesOfPaymentHandlers()->filter(fn($name) => $name === $uniqueName);

        if ($handler->isEmpty()) {
            throw new \Exception("No handler found with name '{$uniqueName}'");
        }

        if ($handler->count() !== 1) {
            throw new \Exception("Multiple handler found with name '{$uniqueName}'");
        }

        return $handler
            ->map(fn($name, $key) => new $key)
            ->sole();
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

    public function handleGatewayResponse(Request $paymentGatewayServerResponse, string $paymentHandlerName): ?Payment
    {
        $paymentHandler = $this->getPaymentHandlerByName($paymentHandlerName);

        return $paymentHandler->confirmResponseCanBeHandledAndUpdateDatabaseWithTransactionOutcome($paymentGatewayServerResponse);
    }

    public static function redirectWithError(Payment $payment, array $error)
    {
        return redirect($payment->metadata['completion_url'] ?? '/')->withErrors($error);
    }
}
