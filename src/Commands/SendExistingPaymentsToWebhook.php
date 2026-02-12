<?php

namespace Damms005\LaravelMultipay\Commands;

use Damms005\LaravelMultipay\Listeners\SendPaymentWebhookListener;
use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Console\Command;

class SendExistingPaymentsToWebhook extends Command
{
    protected $signature = 'multipay:send-payments-webhook
        {--from= : Start date (Y-m-d)}
        {--to= : End date (Y-m-d)}
        {--chunk=100 : Number of payments per batch}';

    protected $description = 'Send existing successful payments to the configured webhook endpoint';

    protected int $sentCount = 0;

    protected int $failedCount = 0;

    protected int $consecutiveFailures = 0;

    protected const MAX_CONSECUTIVE_FAILURES = 3;

    public function handle(): int
    {
        $webhookUrl = config('laravel-multipay.webhook.url');
        $signingSecret = config('laravel-multipay.webhook.signing_secret');

        if (! $webhookUrl || ! $signingSecret) {
            $this->error('Webhook URL or signing secret not configured.');
            return self::FAILURE;
        }

        if (! class_exists(\Spatie\WebhookServer\WebhookCall::class)) {
            $this->error('spatie/laravel-webhook-server is not installed.');
            return self::FAILURE;
        }

        $query = Payment::successful();

        if ($from = $this->option('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $this->option('to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        $totalCount = $query->count();

        if ($totalCount === 0) {
            $this->info('No successful payments found.');
            return self::SUCCESS;
        }

        $this->info("Sending {$totalCount} payments to webhook...");
        $progressBar = $this->output->createProgressBar($totalCount);
        $chunkSize = (int) $this->option('chunk');

        $query->chunk($chunkSize, function ($payments) use ($webhookUrl, $signingSecret, $progressBar) {
            $paymentsWithoutFeeHead = $payments->filter(
                fn (Payment $payment) => ! isset($payment->metadata['fee_head_id'])
            );

            if ($paymentsWithoutFeeHead->isNotEmpty()) {
                $this->newLine();
                $this->error('Payments missing fee_head_id in metadata:');
                $paymentsWithoutFeeHead->each(function (Payment $payment) {
                    $this->error("  - ID: {$payment->id}, Ref: {$payment->transaction_reference}");
                });
                $this->error('Aborting. Please run the fee head backfill command first.');
                return false;
            }

            $batchPayload = $payments->map(
                fn (Payment $payment) => SendPaymentWebhookListener::buildPayload($payment)
            )->values()->all();

            try {
                \Spatie\WebhookServer\WebhookCall::create()
                    ->url($webhookUrl)
                    ->payload(['payments' => $batchPayload])
                    ->useSecret($signingSecret)
                    ->dispatch();

                $this->sentCount += $payments->count();
                $this->consecutiveFailures = 0;
            } catch (\Exception $e) {
                $this->consecutiveFailures++;
                $this->failedCount += $payments->count();

                $this->newLine();
                $this->error("Batch failed: {$e->getMessage()}");

                if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->error('Aborting after ' . self::MAX_CONSECUTIVE_FAILURES . ' consecutive failures. Endpoint may be down.');
                    return false;
                }
            }

            $progressBar->advance($payments->count());
        });

        $progressBar->finish();
        $this->newLine(2);
        $this->info("Sent: {$this->sentCount}, Failed: {$this->failedCount}");

        return $this->failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
