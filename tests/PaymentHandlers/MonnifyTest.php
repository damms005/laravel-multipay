<?php

use Damms005\LaravelMultipay\Enums\DebitOutcome;
use Damms005\LaravelMultipay\Enums\MandateStatus;
use Damms005\LaravelMultipay\Services\Monnify\MonnifyApiClient;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Monnify;
use Damms005\LaravelMultipay\ValueObjects\MandateRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('laravel-multipay.monnify.api_key', 'MK_TEST_APIKEY');
    config()->set('laravel-multipay.monnify.secret_key', 'MONNIFY_SECRET');
    config()->set('laravel-multipay.monnify.contract_code', '675234136342');
    config()->set('laravel-multipay.monnify.base_url', 'https://sandbox.monnify.com');
    config()->set('laravel-multipay.monnify.token_cache.enabled', true);

    Cache::flush();

    $this->payment = createPayment();
    $this->payment->update(['payment_processor_name' => 'Monnify']);
    $this->payment->refresh();
});

function monnifyLoginResponse(string $token = 'access-token-1', int $expiresIn = 3600): array
{
    return [
        'requestSuccessful' => true,
        'responseMessage' => 'success',
        'responseCode' => '0',
        'responseBody' => [
            'accessToken' => $token,
            'expiresIn' => $expiresIn,
        ],
    ];
}

function monnifyOk(array $responseBody, string $message = 'success'): array
{
    return [
        'requestSuccessful' => true,
        'responseMessage' => $message,
        'responseCode' => '0',
        'responseBody' => $responseBody,
    ];
}

function monnifyDecline(string $message, array $responseBody = ['paymentStatus' => 'FAILED']): array
{
    return [
        'requestSuccessful' => true,
        'responseMessage' => $message,
        'responseCode' => '0',
        'responseBody' => $responseBody,
    ];
}

function monnifyWebhookRequest(array $payload, string $secretKey = 'MONNIFY_SECRET', ?string $signatureOverride = null): Request
{
    $body = json_encode($payload);
    $signature = $signatureOverride ?? hash_hmac('sha512', $body, $secretKey);

    return Request::create('/payment/completed/notify', 'POST', [], [], [], [
        'HTTP_MONNIFY_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('exchanges the api key and secret for a bearer token and caches it across calls', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v2/transactions/*' => Http::response(monnifyOk(['paymentStatus' => 'PENDING'])),
    ]);

    $handler = new Monnify();
    $handler->reQuery($this->payment);
    $handler->reQuery($this->payment);

    $loginCalls = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/auth/login'))
        ->count();

    expect($loginCalls)->toBe(1);
});

it('re-authenticates once when the provider reports the token as expired', function () {
    $statusResponses = Http::sequence()
        ->push(['requestSuccessful' => false, 'responseMessage' => 'Access token has expired'], 401)
        ->push(monnifyOk(['paymentStatus' => 'PENDING']));

    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v2/transactions/*' => $statusResponses,
    ]);

    (new Monnify())->reQuery($this->payment);

    $loginCalls = collect(Http::recorded())
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/auth/login'))
        ->count();

    expect($loginCalls)->toBe(2);
});

it('initialises a transaction and redirects the payer to the monnify checkout', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/merchant/transactions/init-transaction' => Http::response(monnifyOk([
            'checkoutUrl' => 'https://sandbox.monnify.com/checkout/MNFY-123',
            'transactionReference' => 'MNFY|63|20220126120647|000042',
            'paymentReference' => $this->payment->transaction_reference,
        ])),
    ]);

    $response = (new Monnify())->proceedToPaymentGateway($this->payment, 'https://app.test/done');

    expect($response->getTargetUrl())->toBe('https://sandbox.monnify.com/checkout/MNFY-123');

    $this->payment->refresh();

    expect($this->payment->processor_transaction_reference)->toBe('MNFY|63|20220126120647|000042')
        ->and($this->payment->metadata['monnify_checkout_url'])->toBe('https://sandbox.monnify.com/checkout/MNFY-123');
});

it('marks a paid transaction successful and stores the reusable card token', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v2/transactions/*' => Http::response(monnifyOk([
            'transactionReference' => 'MNFY|63|20220126120647|000042',
            'paymentReference' => $this->payment->transaction_reference,
            'paymentStatus' => 'PAID',
            'amountPaid' => '500.00',
            'paidOn' => '26/01/2022 12:06:52 PM',
            'cardDetails' => ['cardToken' => 'MNFY_A1BFC27BDE30453E95FA4E5E4055C9D8'],
        ])),
    ]);

    $payment = (new Monnify())->processValueForTransaction($this->payment->transaction_reference);

    expect((bool) $payment->is_success)->toBeTrue()
        ->and($payment->processor_returned_amount)->toBe('500.00')
        ->and($payment->metadata['monnify_card_token'])->toBe('MNFY_A1BFC27BDE30453E95FA4E5E4055C9D8')
        ->and((string) $payment->processor_returned_transaction_date)->toStartWith('2022-01-26 12:06:52');
});

it('leaves a still-processing transaction requeryable rather than recording it as failed', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v2/transactions/*' => Http::response(monnifyOk(['paymentStatus' => 'PENDING'])),
    ]);

    $payment = (new Monnify())->processValueForTransaction($this->payment->transaction_reference);

    expect($payment->is_success)->toBeNull();
});

it('records a genuinely failed transaction as unsuccessful', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v2/transactions/*' => Http::response(monnifyOk(['paymentStatus' => 'FAILED'])),
    ]);

    $payment = (new Monnify())->processValueForTransaction($this->payment->transaction_reference);

    expect((int) $payment->is_success)->toBe(0)
        ->and($payment->processor_returned_response_description)->toBe('FAILED');
});

it('creates a mandate that is awaiting the payer and carries the authorisation link', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/direct-debit/mandate/create' => Http::response(monnifyOk([
            'mandateReference' => 'GB-MANDATE-1',
            'mandateStatus' => 'PENDING_AUTHORIZATION',
            'authorizationUrl' => 'https://sandbox.monnify.com/mandate/authorize/abc',
        ])),
    ]);

    $mandate = (new Monnify())->createMandate(new MandateRequest(
        reference: 'GB-MANDATE-1',
        payerName: 'Ada Parent',
        payerEmail: 'ada@example.com',
        accountNumber: '0123456789',
        bankCode: '058',
        narration: 'GradeBoost AI subscription',
        amount: '7500',
        startDate: '2026-08-04 00:00:00',
    ));

    expect($mandate->reference)->toBe('GB-MANDATE-1')
        ->and($mandate->status)->toBe(MandateStatus::PendingAuthorization)
        ->and($mandate->isAwaitingPayer())->toBeTrue()
        ->and($mandate->isDebitable())->toBeFalse()
        ->and($mandate->authorizationUrl)->toBe('https://sandbox.monnify.com/mandate/authorize/abc');
});

it('reports a mandate as terminal once the provider has killed it', function (string $providerStatus) {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/direct-debit/mandate/status*' => Http::response(monnifyOk([
            'mandateReference' => 'GB-MANDATE-1',
            'mandateStatus' => $providerStatus,
        ])),
    ]);

    $mandate = (new Monnify())->getMandateStatus('GB-MANDATE-1');

    expect($mandate->isTerminal())->toBeTrue()
        ->and($mandate->isDebitable())->toBeFalse();
})->with(['CANCELLED', 'SUSPENDED', 'EXPIRED', 'AUTHORIZATION_EXPIRED']);

it('treats an activated mandate as debitable', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/direct-debit/mandate/status*' => Http::response(monnifyOk([
            'mandateReference' => 'GB-MANDATE-1',
            'mandateStatus' => 'ACTIVATED',
        ])),
    ]);

    $mandate = (new Monnify())->getMandateStatus('GB-MANDATE-1');

    expect($mandate->isDebitable())->toBeTrue()
        ->and($mandate->isTerminal())->toBeFalse();
});

it('reports a successful mandate debit', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/direct-debit/mandate/debit' => Http::response(monnifyOk([
            'transactionReference' => 'MNFY|99|20260804120647|000042',
            'paymentReference' => 'GB-RENEWAL-1',
            'paymentStatus' => 'PAID',
            'amountPaid' => '7500.00',
        ])),
    ]);

    $result = (new Monnify())->debitMandate('GB-MANDATE-1', '7500', 'GB-RENEWAL-1', 'Renewal');

    expect($result->outcome)->toBe(DebitOutcome::Succeeded)
        ->and($result->isSuccessful())->toBeTrue()
        ->and($result->amountPaid)->toBe('7500.00');
});

it('classifies a declined debit so the caller knows whether retrying can ever work', function (
    string $providerMessage,
    DebitOutcome $expectedOutcome,
    bool $retryable,
) {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/direct-debit/mandate/debit' => Http::response(monnifyDecline($providerMessage)),
    ]);

    $result = (new Monnify())->debitMandate('GB-MANDATE-1', '7500', 'GB-RENEWAL-1', 'Renewal');

    expect($result->outcome)->toBe($expectedOutcome)
        ->and($result->outcome->isRetryable())->toBe($retryable)
        ->and($result->isSuccessful())->toBeFalse();
})->with([
    'insufficient funds is worth retrying later' => ['Insufficient funds in account', DebitOutcome::InsufficientFunds, true],
    'provider downtime is worth retrying sooner' => ['Request timed out, please try again', DebitOutcome::ProviderUnavailable, true],
    'a dead mandate can never be retried' => ['Mandate has been cancelled by customer', DebitOutcome::MandateDead, false],
    'a dead card can never be retried' => ['Card has expired', DebitOutcome::InstrumentDead, false],
]);

it('flags a dead mandate or card as needing a new instrument rather than a retry', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/direct-debit/mandate/debit' => Http::response(monnifyDecline('Mandate limit exceeded')),
    ]);

    $result = (new Monnify())->debitMandate('GB-MANDATE-1', '7500', 'GB-RENEWAL-1', 'Renewal');

    expect($result->outcome->requiresNewInstrument())->toBeTrue()
        ->and($result->outcome->retryAfterHours())->toBeNull();
});

it('charges a stored card token using the email captured at tokenisation', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/merchant/cards/charge-card-token' => Http::response(monnifyOk([
            'transactionReference' => 'MNFY|63|20220126120647|000042',
            'paymentReference' => 'GB-RENEWAL-2',
            'paymentStatus' => 'PAID',
            'amountPaid' => '7500.00',
            'cardDetails' => ['cardToken' => 'MNFY_A1BFC27BDE30453E95FA4E5E4055C9D8'],
        ])),
    ]);

    $result = (new Monnify())->chargeStoredInstrument(
        'MNFY_A1BFC27BDE30453E95FA4E5E4055C9D8',
        'ada@example.com',
        '7500',
        'GB-RENEWAL-2',
        'Renewal',
    );

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->storedInstrumentToken)->toBe('MNFY_A1BFC27BDE30453E95FA4E5E4055C9D8');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'charge-card-token')
        && $request['customerEmail'] === 'ada@example.com'
        && $request['cardToken'] === 'MNFY_A1BFC27BDE30453E95FA4E5E4055C9D8');
});

it('extracts a reusable token from an earlier transaction', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v2/transactions/*' => Http::response(monnifyOk([
            'paymentStatus' => 'PAID',
            'cardDetails' => ['cardToken' => 'MNFY_TOKEN_XYZ'],
        ])),
    ]);

    expect((new Monnify())->extractStoredInstrumentToken('MNFY|63|20220126120647|000042'))
        ->toBe('MNFY_TOKEN_XYZ');
});

it('returns no token for a transaction that never produced one', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v2/transactions/*' => Http::response(monnifyOk(['paymentStatus' => 'PAID'])),
    ]);

    expect((new Monnify())->extractStoredInstrumentToken('MNFY|63|20220126120647|000042'))->toBeNull();
});

it('accepts a correctly signed webhook and marks the payment paid', function () {
    $this->payment->update(['processor_transaction_reference' => 'MNFY|63|20220126120647|000042']);

    $request = monnifyWebhookRequest([
        'eventType' => 'SUCCESSFUL_TRANSACTION',
        'eventData' => [
            'paymentReference' => $this->payment->transaction_reference,
            'transactionReference' => 'MNFY|63|20220126120647|000042',
            'amountPaid' => '500.00',
            'paymentStatus' => 'PAID',
            'cardDetails' => ['cardToken' => 'MNFY_TOKEN_FROM_WEBHOOK'],
        ],
    ]);

    $payment = (new Monnify())->handleExternalWebhookRequest($request);

    expect((bool) $payment->is_success)->toBeTrue()
        ->and($payment->processor_returned_amount)->toBe('500.00')
        ->and($payment->metadata['monnify_card_token'])->toBe('MNFY_TOKEN_FROM_WEBHOOK');
});

it('captures the payer source account from a bank transfer so a mandate can be pre-filled', function () {
    $request = monnifyWebhookRequest([
        'eventType' => 'SUCCESSFUL_TRANSACTION',
        'eventData' => [
            'paymentReference' => $this->payment->transaction_reference,
            'transactionReference' => 'MNFY|63|20260804120647|000042',
            'amountPaid' => '3900.00',
            'paymentStatus' => 'PAID',
            'paymentMethod' => 'ACCOUNT_TRANSFER',
            'paymentSourceInformation' => [
                [
                    'bankCode' => '058',
                    'amountPaid' => '3900.00',
                    'accountName' => 'ADA PARENT',
                    'sessionId' => '0580002608041206470000420001',
                    'accountNumber' => '0123456789',
                ],
            ],
        ],
    ]);

    $payment = (new Monnify())->handleExternalWebhookRequest($request);

    expect($payment->metadata['monnify_payment_source'])->toBe([
        'account_number' => '0123456789',
        'bank_code' => '058',
        'account_name' => 'ADA PARENT',
    ]);
});

it('records no payer source when a transfer reports no usable account details', function (mixed $paymentSourceInformation) {
    $request = monnifyWebhookRequest([
        'eventType' => 'SUCCESSFUL_TRANSACTION',
        'eventData' => [
            'paymentReference' => $this->payment->transaction_reference,
            'paymentStatus' => 'PAID',
            'paymentSourceInformation' => $paymentSourceInformation,
        ],
    ]);

    $payment = (new Monnify())->handleExternalWebhookRequest($request);

    expect($payment->metadata)->not->toHaveKey('monnify_payment_source');
})->with([
    'a card payment reports no source account' => [null],
    'an empty source list' => [[]],
    'a source missing the account number' => [[['bankCode' => '058']]],
    'a source missing the bank code' => [[['accountNumber' => '0123456789']]],
]);

it('rejects a webhook whose signature does not match', function () {
    $request = monnifyWebhookRequest([
        'eventType' => 'SUCCESSFUL_TRANSACTION',
        'eventData' => ['paymentReference' => $this->payment->transaction_reference],
    ], signatureOverride: 'clearly-wrong-signature');

    expect(fn () => (new Monnify())->handleExternalWebhookRequest($request))
        ->toThrow(Exception::class, 'Monnify webhook signature verification failed.');
});

it('refuses to pretend monnify has provider-managed plans or subscriptions', function () {
    $handler = new Monnify();

    expect(fn () => $handler->createPaymentPlan('n', '1', 'monthly', 'd', 'NGN'))
        ->toThrow(Exception::class, 'Monnify has no server-side payment plans')
        ->and(fn () => $handler->subscribeToPlan(auth()->user(), new Damms005\LaravelMultipay\Models\PaymentPlan(), 'ref'))
        ->toThrow(Exception::class, 'Monnify has no server-side subscriptions');
});

it('surfaces a provider-level rejection as an exception rather than a silent failure', function () {
    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse()),
        'sandbox.monnify.com/api/v1/direct-debit/mandate/create' => Http::response([
            'requestSuccessful' => false,
            'responseMessage' => 'Invalid bank code',
            'responseCode' => '99',
        ]),
    ]);

    expect(fn () => (new Monnify())->createMandate(new MandateRequest(
        reference: 'GB-MANDATE-2',
        payerName: 'Ada Parent',
        payerEmail: 'ada@example.com',
        accountNumber: '0123456789',
        bankCode: 'nope',
        narration: 'GradeBoost AI subscription',
        amount: '7500',
        startDate: '2026-08-04 00:00:00',
    )))->toThrow(Exception::class, 'Invalid bank code');
});

it('scopes the cached token to the credentials in play so sandbox and live never share one', function () {
    $sandbox = new MonnifyApiClient('KEY_A', 'SECRET_A', 'CONTRACT', 'https://sandbox.monnify.com');
    $live = new MonnifyApiClient('KEY_B', 'SECRET_B', 'CONTRACT', 'https://api.monnify.com');

    Http::fake([
        'sandbox.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse('sandbox-token')),
        'api.monnify.com/api/v1/auth/login' => Http::response(monnifyLoginResponse('live-token')),
    ]);

    expect($sandbox->accessToken())->toBe('sandbox-token')
        ->and($live->accessToken())->toBe('live-token')
        ->and($sandbox->accessToken())->toBe('sandbox-token');
});
