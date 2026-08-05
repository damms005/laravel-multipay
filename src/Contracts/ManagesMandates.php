<?php

namespace Damms005\LaravelMultipay\Contracts;

use Damms005\LaravelMultipay\ValueObjects\DebitResult;
use Damms005\LaravelMultipay\ValueObjects\Mandate;
use Damms005\LaravelMultipay\ValueObjects\MandateRequest;

interface ManagesMandates
{
    public function createMandate(MandateRequest $request): Mandate;

    public function getMandateStatus(string $mandateReference): Mandate;

    public function debitMandate(
        string $mandateReference,
        string $amount,
        string $paymentReference,
        string $narration,
    ): DebitResult;

    public function getDebitStatus(string $paymentReference): DebitResult;

    public function cancelMandate(string $mandateReference): Mandate;
}
