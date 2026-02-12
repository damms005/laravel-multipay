# Laravel Multipay 💸

![Art image for laravel-multipay](https://banners.beyondco.de/Laravel%20Multipay.png?theme=light&packageManager=composer+require&packageName=damms005%2Flaravel-multipay&pattern=glamorous&style=style_1&description=An+opinionated+Laravel+package+for+handling+payments%2C+complete+with+blade+views&md=1&showWatermark=1&fontSize=100px&images=cash&widths=350)

![GitHub](https://img.shields.io/github/license/damms005/laravel-multipay)
![GitHub tag (with filter)](https://img.shields.io/github/v/tag/damms005/laravel-multipay)
[![Total Downloads](https://img.shields.io/packagist/dt/damms005/laravel-multipay.svg)](https://packagist.org/packages/damms005/laravel-multipay)
![GitHub Workflow Status (with event)](https://img.shields.io/github/actions/workflow/status/damms005/laravel-multipay/run-tests.yml)

An opinionated Laravel package to handle payments, complete with blade views, routing, and everything in-between.

Whether you want to quickly bootstrap payment processing for your Laravel applications, or you want a way to test supported payment processors, this package's got you!

> Although opinionated, this package allows you to "theme" the views. It achieves this theming by
> `@extend()`ing whatever view you specify in `config('laravel-multipay.extended_layout')` (defaults to `layout.app`).

## Requirements:
This package is [tested against:](https://github.com/damms005/laravel-multipay/blob/d1a15bf762ba2adabc97714f1565c6c0f0fcd58d/.github/workflows/run-tests.yml#L16-17)
- PHP ^8.2
- Laravel 11/12

## Currently supported payment handlers

Currently, this package supports the following online payment processors/handlers

-   [Paystack](https://paystack.com)
-   [Remita](http://remita.net)
-   [Flutterwave](https://flutterwave.com)**
-   [Interswitch](https://www.interswitchgroup.com)**
-   [UnifiedPayments](https://unifiedpayments.com)**

> [!NOTE]
> _key_:
> ** for the indicated providers, a few features may be missing. PRs welcomed if you cannot afford the wait 😉

> [!TIP]
> Your preferred payment handler is not yet supported? Please consider [opening the appropriate issue type](https://github.com/damms005/laravel-multipay/issues/new?assignees=&labels=&template=addition-of-new-payment-handler.md&title=Addition+of+new+payment+handler+-+%5Bpayment+handler+name+here%5D).

> [!TIP]
> Adding a new payment handler is straight-forward. Simply add a class that extends `Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler` and implement `Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface`

> [!NOTE]
> Payment providers that you so register as described above are resolvable from the [Laravel Container](https://laravel.com/docs/9.x/container) to improve the flexibility of this package and improve DX.

## Installation

```bash
composer require damms005/laravel-multipay
```

### Publish the config file.

```bash
php artisan vendor:publish --tag=laravel-multipay-config
```

### Run migrations.

```bash
php artisan migrate
```

#### Demo Repo
I [published an open source app](https://github.com/damms005/nft-marketplace) that uses this payment package. It is also an excellent example of a Laravel app that uses [Laravel Vite](https://laravel.com/docs/9.x/vite#main-content) and leverages on [Laravel Echo](https://laravel.com/docs/9.x/broadcasting#client-side-installation) to provide realtime experience via public and private channels using [Laravel Websocket](https://beyondco.de/docs/laravel-websockets), powered by [Livewire](https://laravel-livewire.com/docs).

### Test drive 🚀

Want to take things for a spin? Visit `/payment/test-drive` (`route('payment.test-drive')` provided by this package) .
For [Paystack](https://paystack.com), ensure to set `paystack_secret_key` key in the `laravel-multipay.php` config file that you published previously at installation. You can get your key from your [settings page](https://dashboard.paystack.co/#/settings/developer).

> **Warning** <br />
> Ensure you have [TailwindCSS installed](https://tailwindcss.com/docs/installation), then add this package's views to the `content` key of your `tailwind.config.js` configuration file, like below:
```js
    content: [
        ...,
        './vendor/damms005/laravel-multipay/views/**/*.blade.php',
    ],
    ...
```

### Needed Third-party Integrations:

-   Flutterwave: If you want to use Flutterwave, ensure to get your API details [from the dashboard](https://dashboard.flutterwave.com/dashboard/settings/apis), and use it to set the following variables in your `.env` file:

```env
FLW_PUBLIC_KEY=FLWPUBK-xxxxxxxxxxxxxxxxxxxxx-X
FLW_SECRET_KEY=FLWSECK-xxxxxxxxxxxxxxxxxxxxx-X
FLW_SECRET_HASH=hash-123xxxxxxxxxxxxxxxxxxx-X
```

-   Paystack: Paystack requires a secret key. Go to [the Paystack dashboard](https://dashboard.paystack.co/#/settings/developer) to obtain one, and use it to set the following variable:

```env
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxxxxxxx
PAYSTACK_TERMINAL_ID=xxxxxxxxxxxxxxxxxxxxx
```

> The `PAYSTACK_TERMINAL_ID` is only required if you intend to use [Paystack Terminal](https://paystack.com/terminal/) for payment processing.

-   Remita: Ensure to set the following environment variables:

```env
REMITA_MERCHANT_ID=xxxxxxxxxxxxxxxxxxxxx
REMITA_API_KEY=xxxxxxxxxxxxxxxxxxxxx
```

> For most of the above environment variables, you should rather use the (published) config file to set the corresponding values.

## Usage

### Typical process-flow

#### Step 1

Send a `POST` request to `/payment/details/confirm` (`route('payment.show_transaction_details_for_user_confirmation')` provided by this package).

Check the [InitiatePaymentRequest](src/Http/Requests/InitiatePaymentRequest.php#L28) form request class to know the values you are to post to this endpoint. (tip: you can also check [test-drive/pay.blade.php](views/test-drive/pay.blade.php)).

This `POST` request will typically be made by submitting a form from your frontend to the route described above.

> [!NOTE]
> if you need to store additional/contextual data with this payment, you can include such data in the request, in a field named `metadata`. The value must be a valid JSON string.

#### Step 2

Upon user confirmation of transaction, user is redirected to the appropriate payment handler's gateway.

#### Step 3

When user is done with the transaction on the payment handler's end (either successfully paid, or declined transaction), user is redirected
back to `/payment/completed` (`route('payment.finished.callback_url')` provided by this package) .

### Metadata Usage

In the payment initiation request in [Step 1](#step-1), you can provide a `metadata` field. This field is stored in the `metadata` column of the `payments` table, and available as `AsArrayObject::class` property of the `Payment` model and it provides powerful customization options for individual payments.

The metadata should be a valid JSON string containing key-value pairs that modify payment behavior.

#### Available Metadata Keys

**`completion_url`**
- After successful payment, the user will be redirected to the URL specified by this key instead of the default payment completion page
- When user is redirected to the specified URL, the transaction reference will be included as `transaction_reference` in the URL query string

**`payment_processor`**
- Use this key to dynamically set the payment handler for the specific transaction
- Valid values are any of [the providers listed above](#currently-supported-payment-handlers)
- This will override the default payment processor configuration

**`split_code`** (Paystack only)
- When using Paystack, you can use this key to specify a split code to process the transaction as a [Paystack Multi-split Transaction](https://paystack.com/docs/payments/multi-split-payments)
- This feature is only available when using Paystack as the payment handler

## Payment Conflict Resolution (PCR)

If for any reason, your user/customer claims that the payment they made was successful but that your platform did not reflect such successful payment, this PCR feature enables you to resolve such claims by simply calling:

```php
/**
 * @var Damms005\LaravelMultipay\ValueObjects\ReQuery $outcome
 */
$outcome = LaravelMultipay::reQueryUnsuccessfulPayment($payment)
```

The payment will be re-resolved and the payment will be updated in the database. If the payment is successful, the `SuccessfulLaravelMultipayPaymentEvent` event will be fired, so you can run any domain/application-specific procedures.

## WebHooks Payment Notifications (optional)
One of the benefits of this package is to remove the need for you to have to deal with payment webhooks. Depending on your needs, the event handling may suffice for your use case.

If you need webhook notifications from payment providers, use the webhook endpoint provided by this package: `route('payment.external-webhook-endpoint')`.

> If you use this payment notification URL feature, ensure that in the handler for `SuccessfulLaravelMultipayPaymentEvent`, you have not previously handled the event for that same payment.

## Events

### SuccessfulLaravelMultipayPaymentEvent

If there are additional steps you want to take upon successful payment, listen for `SuccessfulLaravelMultipayPaymentEvent`. This event will be fired whenever a successful payment occurs, with its corresponding `Payment` model.

## Paystack Terminal

[Paystack Terminal](https://paystack.com/terminal/) allows you to process payments on physical payment terminals. This feature is useful for point-of-sale (POS) systems and retail environments.

### Prerequisites

1. Ensure you have `PAYSTACK_SECRET_KEY` configured in your `.env` file
2. Obtain your Terminal ID from [Paystack Dashboard](https://dashboard.paystack.co/#/settings/terminals)
3. Set the `PAYSTACK_TERMINAL_ID` in your `.env` file:

```env
PAYSTACK_SECRET_KEY=sk_test_xxxxxxxxxxxxxxxxxxxxx
PAYSTACK_TERMINAL_ID=xxxxxxxxxxxxxxxxxxxxx
```

Alternatively, you can set the Terminal ID dynamically in your session:

```php
session(['multipay::paystack_terminal_id' => 'your_terminal_id']);
```

### Usage

The Paystack Terminal functionality is provided via the `Terminal` class:

```php
use Damms005\LaravelMultipay\Services\PaymentHandlers\PaystackTerminal\Terminal;

$terminal = app(Terminal::class);
```

### Creating a Payment Request

Create a payment request that can be pushed to a terminal:

```php
$payment = $terminal->createPaymentRequest(
    model: 'App\Models\User',
    modelId: 123,
    email: 'customer@example.com',
    description: 'Product purchase',
    amount: 50000  // Amount in kobo (50,000 kobo = 500 NGN)
);
```

This creates a payment record and returns a `Payment` model instance with the payment details stored in metadata.

### Checking Terminal Status

Verify that the terminal hardware is online and ready before pushing payments:

```php
try {
    $status = $terminal->waitForTerminalHardware();
    // Terminal is online
} catch (\Exception $e) {
    // Terminal is offline or not configured
}
```

### Pushing Payment to Terminal

Send a payment request to the terminal for processing:

```php
try {
    $eventId = $terminal->pushToTerminal($payment);
    // Payment has been pushed to terminal
} catch (\Exception $e) {
    // Failed to push to terminal
}
```

The returned `$eventId` can be used to track the payment request delivery status.

### Verifying Terminal Receipt

Confirm that the terminal received the payment request (within 48 hours of creation):

```php
try {
    $result = $terminal->terminalReceivedPaymentRequest($eventId);
    // Terminal has received the payment request
} catch (\Exception $e) {
    // Could not verify receipt
}
```

### Error Handling

The Terminal class throws `\Exception` on failures. Common scenarios include:

- Terminal ID not configured
- Terminal hardware offline
- Invalid payment request data
- Network errors communicating with Paystack API

Always wrap Terminal method calls in try-catch blocks for proper error handling.

## Webhook Push Notifications

When a payment succeeds, this package can automatically send a webhook to an external system (e.g. a financial tracking service). This uses [spatie/laravel-webhook-server](https://github.com/spatie/laravel-webhook-server) under the hood.

### Setup

1. Install the webhook server package:

```bash
composer require spatie/laravel-webhook-server
```

2. Set the webhook URL and signing secret in your `.env`:

```env
LARAVEL_MULTIPAY_WEBHOOK_URL=https://your-receiving-app.com/api/webhooks/payments
LARAVEL_MULTIPAY_WEBHOOK_SIGNING_SECRET=your-shared-secret
```

3. Create a class that implements `Damms005\LaravelMultipay\Contracts\WebhookPayloadPackager`:

```php
use Damms005\LaravelMultipay\Models\Payment;
use Damms005\LaravelMultipay\Contracts\WebhookPayloadPackager;

class MyWebhookPayloadPackager implements WebhookPayloadPackager
{
    public function getWebhookPayload(Payment $payment): array
    {
        return [
            'transaction_reference' => $payment->transaction_reference,
            'amount_paid' => $payment->original_amount_displayed_to_user,
            'payment_processor_name' => $payment->payment_processor_name,
            // ...add any app-specific data you need
        ];
    }
}
```

4. Register your packager in a service provider:

```php
config()->set(
    'laravel-multipay.webhook.payload_packager',
    \App\Services\MyWebhookPayloadPackager::class,
);
```

Once configured, every `SuccessfulLaravelMultipayPaymentEvent` will trigger a signed webhook POST to the configured URL with the payload returned by your packager.

### Backfilling Existing Payments

To send existing successful payments to the webhook endpoint:

```bash
php artisan multipay:send-payments-webhook
```

Options:
- `--from=YYYY-MM-DD` — only payments created on or after this date
- `--to=YYYY-MM-DD` — only payments created on or before this date
- `--chunk=100` — number of payments per batch (default: 100)

The command fail-fast aborts after 3 consecutive batch failures.

## Testing

```bash
composer test
```

## Credits

This package is made possible by the nice works done by the following awesome projects:

-   [yabacon/paystack-php](https://github.com/yabacon/paystack-php)
-   [kingflamez/laravelrave](https://github.com/kingflamez/laravelrave)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
