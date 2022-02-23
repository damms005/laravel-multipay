<?php

use Mockery\Mock;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Config;
use Damms005\LaravelCashier\Models\Payment;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

use Damms005\LaravelCashier\Services\PaymentHandlers\Remita;
use Illuminate\Contracts\View\View;

beforeEach(function () {

    require_once(__DIR__ . "/../../database/factories/PaymentFactory.php");

    $this->payment = (new \PaymentFactory)->create();
});

it('renders auto-submitted payment form', function () {
    /**
     * @var Mock<TObject>
     */
    $mock = mock(Remita::class);
    $mock->makePartial();
    $mock->shouldAllowMockingProtectedMethods();

    $mock->expect(
        getRrrToInitiatePayment: function () {
            return 'abc-123';
        },
    );

    $autoForm = $mock->renderAutoSubmittedPaymentForm($this->payment, 'foo');

    assertTrue(is_subclass_of($autoForm, View::class));
    assertStringContainsString('Loading...', $autoForm->render());
});

it("can handle Remita payment webhook ingress", function () {
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
     * @var Mock<TObject>
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
            return new Payment([
                'user_id' => 1,
                'original_amount_displayed_to_user' => 2,
                'transaction_currency' => 2,
                'transaction_description' => 'foo-bar',
                'transaction_reference' => 'foo-bar',
                'payment_processor_name' => 'foo-bar',
            ]);
        },
        getUserByEmail: function ($email) {
            $user = new User();
            $user->id = 1;

            return $user;
        },
    );

    //Act & Assert
    expect(get_class($mock->handleExternalWebhookRequest($request)))->toEqual(Payment::class);
});
