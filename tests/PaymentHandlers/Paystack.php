<?php

use Mockery\Mock;
use Yabacon\Paystack as PaystackHelper;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Paystack;

beforeEach(function () {
    $this->payment = createPayment();
    $this->payment->update(['payment_processor_name' => 'Paystack']);

    $this->payment->refresh();
});

it('detects double payment', function () {
    expect((new Paystack())->paymentIsUnsettled($this->payment))->toBeTrue();
});

it('resumes unsettled payments', function () {
    /**
     * @var Mock<TObject>
     */
    $paystackHelperMock = mock(PaystackHelper::class);

    /**
     * @var Mock<TObject>
     */
    $transactionMock = mock('null');

    $transactionMock
        ->expects('initialize')
        ->andReturn((object)[
            'status' => true,
            'data' => (object)[
                'reference' => 'reference',
                'authorization_url' => 'someplace-on-the-internet',
            ],
        ]);

    $paystackHelperMock->transaction = $transactionMock;

    app()->bind(PaystackHelper::class, fn () => $paystackHelperMock);

    (new Paystack())->proceedToPaymentGateway($this->payment, 'nowhere');

    $this->payment->refresh();

    /** @var \Illuminate\Http\RedirectResponse */
    $response = ((new Paystack())->resumeUnsettledPayment($this->payment));

    expect($response->getTargetUrl())->toBe('someplace-on-the-internet');
});
