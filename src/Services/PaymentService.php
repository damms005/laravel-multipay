<?php

namespace Damms005\LaravelCashier\Services;

use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;

class PaymentService
{
	public static function storePaymentAndShowUserBeforeProcessing(PaymentHandlerInterface $paymentHandler, int $user_id, float $amount, string $description, string $currency, string $transaction_reference, string | null $view, $metadata = null)
	{
		$basePaymentHandler = new BasePaymentHandler($paymentHandler);

		return $basePaymentHandler->storePaymentAndShowUserBeforeProcessing($user_id, $amount, $description, $currency, $transaction_reference, null, null, $view, $metadata);
	}

	public static function getPaymentHandlerByName(string $paymentHandlerName): PaymentHandlerInterface
	{
		try {
			$handlerFqcn = "\\Damms005\\LaravelCashier\\Services\\PaymentHandlers\\{$paymentHandlerName}";

			/** @var PaymentHandlerInterface */
			$paymentHandler = new $handlerFqcn();

			return $paymentHandler;
		} catch (\Throwable$th) {
			throw new \Exception("Could not get payment processor: {$paymentHandlerName}");
		}
	}
}
