<?php

namespace Damms005\LaravelMultipay\Webhooks\Monnify;

use Damms005\LaravelMultipay\Exceptions\NonActionableWebhookPaymentException;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Monnify;
use Damms005\LaravelMultipay\Webhooks\Contracts\WebhookHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class TransactionCompleted implements WebhookHandler
{
    public function isHandlerFor(Request $webhookRequest): bool
    {
        return strtoupper((string) $webhookRequest->input('eventType')) === 'SUCCESSFUL_TRANSACTION';
    }

    public function handle(Request $webhookRequest): Payment
    {
        $paymentReference = $webhookRequest->input('eventData.paymentReference');
        $transactionReference = $webhookRequest->input('eventData.transactionReference');

        $payment = Payment::withTrashed()
            ->where(function ($query) use ($paymentReference, $transactionReference): void {
                $query->when(
                    filled($paymentReference),
                    fn ($builder) => $builder->orWhere('transaction_reference', $paymentReference),
                )->when(
                    filled($transactionReference),
                    fn ($builder) => $builder->orWhere('processor_transaction_reference', $transactionReference),
                );
            })
            ->first();

        if (! $payment) {
            throw new NonActionableWebhookPaymentException(
                new Monnify(),
                "no known payment matches paymentReference {$paymentReference}",
                $webhookRequest,
            );
        }

        $metadata = (array) $payment->metadata;
        $metadata['events'] = $metadata['events'] ?? [];
        $metadata['events']['SUCCESSFUL_TRANSACTION'] = $webhookRequest->all();

        $cardToken = Arr::get($webhookRequest->all(), 'eventData.cardDetails.cardToken');

        if (filled($cardToken)) {
            $metadata['monnify_card_token'] = $cardToken;
        }

        $paymentSource = self::resolvePaymentSource($webhookRequest->input('eventData.paymentSourceInformation'));

        if ($paymentSource !== null) {
            $metadata['monnify_payment_source'] = $paymentSource;
        }

        $payment->update([
            'is_success' => 1,
            'processor_transaction_reference' => $transactionReference ?: $payment->processor_transaction_reference,
            'processor_returned_amount' => (string) $webhookRequest->input('eventData.amountPaid'),
            'processor_returned_transaction_date' => now(),
            'processor_returned_response_description' => (string) $webhookRequest->input('eventData.paymentStatus', 'PAID'),
            'metadata' => $metadata,
        ]);

        return $payment->refresh();
    }

    public static function resolvePaymentSource(mixed $paymentSourceInformation): ?array
    {
        $source = is_array($paymentSourceInformation)
            ? ($paymentSourceInformation[0] ?? $paymentSourceInformation)
            : null;

        if (! is_array($source)) {
            return null;
        }

        $accountNumber = $source['accountNumber'] ?? null;
        $bankCode = $source['bankCode'] ?? null;

        if (blank($accountNumber) || blank($bankCode)) {
            return null;
        }

        return [
            'account_number' => (string) $accountNumber,
            'bank_code' => (string) $bankCode,
            'account_name' => isset($source['accountName']) ? (string) $source['accountName'] : null,
        ];
    }
}
