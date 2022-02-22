<?php

use Damms005\LaravelCashier\Models\Payment;
use Damms005\LaravelCashier\Services\PaymentHandlers\Remita;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

it("can handle payment notification", function () {
    //Arrange
    Config::set("laravel-cashier.paystack_secret_key", 'abc');


    $request = new Request(json_decode('{
				"rrr":"110002071256",
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
     * @var mixed
     */
    $mock = mock(Remita::class);
    $mock->shouldAllowMockingProtectedMethods();
    $mock->makePartial();
    $mock->expect(
        queryRrr: function () {
            $response = new stdClass();
            $response->status = '00';
            $response->email = 'example@mail.com';
            $response->description = 'sample description';
            $response->amount = 12345;

            return $response;
        },
        getPaymentByRrr: function ($rrr) {
            return null;
        },
        getUserByEmail: function ($email) {
            $user = new User();
            $user->id = 1;

            return $user;
        },
        createNewPayment: function (User $user, stdClass $responseBody) {
            return new Payment();
        },
        updateSuccessfulPayment: function ($payment, $responseBody) {
            return $payment;
        }
    );

    //Act & Assert
    expect(get_class($mock->handleExternalWebhookRequest($request)))->toEqual(Payment::class);
});
