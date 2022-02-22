<?php

use Mockery\Mock;
use function Pest\Laravel\post;
use Illuminate\Support\Facades\App;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use function PHPUnit\Framework\assertTrue;
use Damms005\LaravelCashier\Models\Payment;
use Damms005\LaravelCashier\Services\PaymentService;
use Damms005\LaravelCashier\Services\PaymentHandlers\Remita;
use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;

beforeEach(function () {
    config()->push('app.key', 'foo-bar');
});

// TODO: write these tests:
// it("ensures that submission of the form at /payments/test url does not fail", function () {});
// it("processes payment webhooks", function () {});

it("can use all supported payment handlers to initiate payment and handle payment response", function (string $paymentHandlerFqcn) {
    // Arrange
    $paymentSample = [
        'currency' => 'NGN',
        'amount' => '500',
        'user_id' => 1,
        'transaction_description' => 'foo-bar',
        'payment_processor' => Remita::getUniquePaymentHandlerName(),
    ];

    // Act
    $paymentConfirmationPage = post(route('payment.show_transaction_details_for_user_confirmation'), $paymentSample)
        ->assertSee('foo-bar')
        ->assertSee(500.00);

    $payment = Payment::firstOrFail();

    // Assert
    expect(get_class($paymentConfirmationPage))
        ->toEqual('\Illuminate\Contracts\View\View');

    // Act & Assert
    post(route('payment.confirmation.submit'), [
        'transaction_reference' => $payment->transaction_reference
    ])
        ->assertRedirect('mongoli.foo-bar.com');

    // Arrange
    Event::fake();

    /**
     * @var Mock<TObject>
     */
    $mock = mock(PaymentService::class);
    $mock->shouldAllowMockingProtectedMethods();
    $mock->makePartial();
    $mock->expect(
        getPaymentHandlerByName: function () {
            return new Remita;
        },
    );

    App::bind(PaymentService::class, function ($app) use ($mock) {
        return $mock;
    });

    post(route('payment.finished.callback_url'), [
        'RRR' => 12345
    ]);

    Event::assertDispatched(SuccessfulLaravelCashierPaymentEvent::class);
})
    ->only()
    ->with(BasePaymentHandler::getNamesOfPaymentHandlers());

it('ensures that all payment handlers can be initiate for payment processing', function () {
    config(["laravel-cashier.paystack_secret_key" => "sk_test_91017d4bc25b969584699baa67c751fc2d060639"]);

    collect(BasePaymentHandler::getNamesOfPaymentHandlers())
        ->each(function ($handlerName) {
            $paymentHandler = (new PaymentService)->getPaymentHandlerByName($handlerName);

            assertTrue(is_subclass_of($paymentHandler, BasePaymentHandler::class));
            assertTrue($paymentHandler instanceof PaymentHandlerInterface);
        });
});
