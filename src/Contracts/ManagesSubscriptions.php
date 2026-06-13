<?php

namespace Damms005\LaravelMultipay\Contracts;

interface ManagesSubscriptions
{
    /**
     * Disable (suspend) a subscription on the payment provider so that it
     * does not renew on its next payment date. Used to back both "pause"
     * and "cancel" semantics — the distinction between the two is tracked
     * locally via the Subscription status, not by the provider.
     *
     * @throws \Exception when the provider rejects the request
     */
    public function disableSubscription(string $subscriptionCode, string $emailToken): void;

    /**
     * Re-enable a previously disabled subscription on the payment provider
     * so that it resumes renewing.
     *
     * @throws \Exception when the provider rejects the request
     */
    public function enableSubscription(string $subscriptionCode, string $emailToken): void;

    /**
     * Fetch the current state of a subscription from the payment provider.
     * Useful for retrieving the email token required to disable/enable a
     * subscription when it was not captured at creation time.
     *
     * @return array{subscription_code: string, email_token: ?string, status: ?string, next_payment_date: ?string}
     *
     * @throws \Exception when the provider rejects the request
     */
    public function getSubscriptionDetails(string $subscriptionCode): array;
}
