<?php

use Mockery\Mock;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Config;
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Services\PaymentHandlers\Remita;

use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

beforeEach(function () {
    $this->payment = createPayment();
    $this->payment->update(['payment_processor_name' => 'Remita']);

    $this->payment->refresh();
});

it('renders auto-submitted payment form', function () {
    /**
     * @var Mock<TObject>
     */
    $mock = mock(Remita::class);
    $mock->makePartial();
    $mock->shouldAllowMockingProtectedMethods();

    $mock->expects('getRrrToInitiatePayment');

    $autoForm = $mock->proceedToPaymentGateway($this->payment, 'foo');

    assertTrue(is_subclass_of($autoForm, View::class));
    assertStringContainsString('Loading...', $autoForm->render());
});

it('can handle Remita payment webhook ingress', function () {
    //Arrange
    Config::set("laravel-multipay.paystack_secret_key", 'abc');

    $request = new Request(json_decode('{
				"rrr":"110002071256",
				"status":"DUMMY-DATA",
				"channnel":"CARDPAYMENT",
				"billerName":"SYSTEMSPECS",
				"channel":"CARDPAYMENT",
				"amount":200.0,
				"transactiondate":"2020-12-20 00:00:00",
				"debitdate":"2020-12-20 11:17:03",
				"bank":"232",
				"branch":"",
				"serviceTypeId":"2020978",
				"orderRef":"6954148807",
				"orderId":"6954148807",
				"payerName":"Test Test",
				"payerPhoneNumber":"07055542122",
				"payerEmail":"test@test.com.ng",
				"type":"PY",
				"customFieldData":[
					 {
							"DESCRIPTION":"Name On Account",
							"PIVAL":"Test Test"
					 },
					 {
							"DESCRIPTION":"Walletid",
							"PIVAL":"1234567"
					 }
				],
				"parentRRRDetails":{
				},
				"chargeFee":101.61,
				"paymentDescription":"SYSTEMSPECS WALLET",
				"integratorsEmail":"",
				"integratorsPhonenumber":""
			}
		', true));

    /**
     * @var Mock<TObject>
     */
    $mock = mock(Remita::class);
    $mock->shouldAllowMockingProtectedMethods();
    $mock->makePartial();
    $mock->expects('queryRrr')
        ->andReturnUsing(
            function () {
                $response = new stdClass();
                $response->status = '00';
                $response->email = 'example@mail.com';
                $response->description = 'sample description';
                $response->amount = 12345;

                return $response;
            }
        );

    $mock->expects('getPaymentByRrr')
        ->andReturnUsing(
            function () {
                return new Payment([
                    'user_id' => 1,
                    'original_amount_displayed_to_user' => 2,
                    'transaction_currency' => 2,
                    'transaction_description' => 'magic-foo-bar',
                    'transaction_reference' => 'foo-bar',
                    'payment_processor_name' => 'foo-bar',
                ]);
            }
        );

    $mock->expects('getUserByEmail')
        ->andReturnUsing(
            function () {
                $user = new User();
                $user->id = 1;

                return $user;
            },
        );

    //Act & Assert
    expect($mock->handleExternalWebhookRequest($request))
        ->transaction_description->toEqual('magic-foo-bar');
});

it('can read user-defined service type id in request', function () {
    $this->payment->update(['metadata' => ['remita_service_id' => 'some-cool-value']]);

    $parsedId = (new Remita())->getServiceTypeId($this->payment->refresh());

    expect($parsedId)->toBe('some-cool-value');
});

it('detects double payment', function ($status) {
    $this->payment->update(['processor_returned_response_description' => json_encode(['status' => $status])]);
    $this->payment->refresh();

    if ($status == '00') {
        expect((new Remita())->paymentIsUnsettled($this->payment))->toBeFalse();
    } else {
        expect((new Remita())->paymentIsUnsettled($this->payment))->toBeTrue();
    }
})
    ->with(array_merge((new Remita())->responseCodesIndicatingUnFulfilledTransactionState, [
        '998', // Internal Remita error
        '00'
    ]));
