# Laravel Multipay 💸

![Art image for laravel-multipay](https://banners.beyondco.de/Laravel%20Multipay.png?theme=light&packageManager=composer+require&packageName=damms005%2Flaravel-multipay&pattern=glamorous&style=style_1&description=An+opinionated+Laravel+package+for+handling+payments%2C+complete+with+blade+views&md=1&showWatermark=1&fontSize=100px&images=cash&widths=350)

![GitHub tag (with filter)](https://img.shields.io/github/v/tag/damms005/laravel-multipay)
[![Total Downloads](https://img.shields.io/packagist/dt/damms005/laravel-multipay.svg)](https://packagist.org/packages/damms005/laravel-multipay)

An opinionated Laravel package to handle payments, complete with blade views, routing, and everything in-between.

Whether you want to quickly bootstrap payment processing for your Laravel applications, or you want a way to test supported payment processors, this package's got you covered.

> Although opinionated, this package allows you to "theme" the views. It achieves this theming by
> `@extend()`ing whatever view you specify in `config('laravel-multipay.extended_layout')` (defaults to `layout.app`). This provides a smooth Plug-and-play&trade; experience.

## Requirements:
This package is [tested against:](https://github.com/damms005/laravel-multipay/blob/68731735d50a18f6b8531cb107e63fed5151d0b8/.github/workflows/run-tests.yml#L16-L17)
- Laravel 8/9
- PHP 8.0, 8.1

## Currently supported payment handlers

Currently, this package supports the following online payment processors/handlers

-   [Paystack](https://paystack.com)
-   [Remita](http://remita.net)
-   [Flutterwave](https://flutterwave.com)**
-   [Interswitch](https://www.interswitchgroup.com)**
-   [UnifiedPayments](https://unifiedpayments.com)**

_key_:
`** implementation not yet complete for specified payment handler. PRs welcomed if you cannot afford to wait 😉`

> Your preferred payment handler is not yet supported? Please consider [opening the appropriate issue type](https://github.com/damms005/laravel-multipay/issues/new?assignees=&labels=&template=addition-of-new-payment-handler.md&title=Addition+of+new+payment+handler+-+%5Bpayment+handler+name+here%5D).

> Adding a new payment handler is straight-forward. Simply add a class that extends `Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler`  and implement `Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface`

> **Note** <br />
> Payment providers that you so register as described above are resolved from the [Laravel Container](https://laravel.com/docs/9.x/container) to improve the flexibility of this package and improve DX.

## Installation

You need to do just 3 things:

- Install via composer.

```bash
composer require damms005/laravel-multipay
```

- Publish the config file.

```bash
php artisan vendor:publish --tag=laravel-multipay-config
```

- Run migrations.

```
php artisan migrate
```

## Usage

### Demo Repo
I published an open source app that uses this payment package. It is also an excellent example of a Laravel app that uses [Laravel Vite](https://laravel.com/docs/9.x/vite#main-content) and leverages on [Laravel Echo](https://laravel.com/docs/9.x/broadcasting#client-side-installation) to provide realtime experience via public and private channels using [Laravel Websocket](https://beyondco.de/docs/laravel-websockets), powered by [Livewire](https://laravel-livewire.com/docs). The app is called [NFT Marketplace. Click here to check it out ✌🏼](https://github.com/damms005/nft-marketplace-l9)

### Test drive 🚀

Want to take things for a spin? Visit `/payment/test-drive` (`route('payment.test-drive')`) .
For [Paystack](https://paystack.com), ensure to set `paystack_secret_key` key in the `laravel-multipay.php` config file that you published previously at installation. You can get your key from your [settings page](https://dashboard.paystack.co/#/settings/developer).

> **Warning** <br />
> Ensure you have [TailwindCSS installed](https://tailwindcss.com/docs/installation), then add this package's views to the `content` key of your `tailwind.config.js` configuration file, like below:
```
    content: [
        ...,
        './vendor/damms005/laravel-multipay/views'
    ],
    ...
```

#### Needed Third-party Integrations:

-   Flutterwave: If you want to use Flutterwave, ensure to get your API details [from the dashboard](https://dashboard.flutterwave.com/dashboard/settings/apis), and use it to set the following environmental variables:

```
FLW_PUBLIC_KEY=FLWPUBK-xxxxxxxxxxxxxxxxxxxxx-X
FLW_SECRET_KEY=FLWSECK-xxxxxxxxxxxxxxxxxxxxx-X
FLW_SECRET_HASH='My_lovelysite123'
```

-   Paystack: Paystack requires a secret key. Go to [the Paystack dashboard](https://dashboard.paystack.co/#/settings/developer) to obtain one, and use it to set the following environmental variable:

```
PAYSTACK_SECRET_KEY=FLWPUBK-xxxxxxxxxxxxxxxxxxxxx-X
```

-   Remita: Ensure to set the following environmental variables:

```
REMITA_MERCHANT_ID=xxxxxxxxxxxxxxxxxxxxx-X
REMITA_API_KEY=xxxxxxxxxxxxxxxxxxxxx-X
```

> For most of the above environmental variables, you should rather use the (published) config file to set the corresponding values.

### Typical process-flow

#### Step 1

Send a `POST` request to `/payment/details/confirm` (`route('payment.show_transaction_details_for_user_confirmation')`).

Check the [InitiatePaymentRequest](src/Http/Requests/InitiatePaymentRequest.php#L28) form request class to know the values you are to post to this endpoint. (tip: you can also check [test-drive/pay.blade.php](views/test-drive/pay.blade.php)).

This `POST` request will typically simply be made by submitting a form from your frontend.

> Tip: if you need to store additional/contextual data with this payment, you can include such data in the request, in a field named `metadata`. The value must be a valid JSON string.

#### Step 2

Upon user confirmation of transaction, user is redirected to the appropriate payment handler's gateway.

#### Step 3

When user is done with the transaction on the payment handler's end (either successfully paid, or declined transaction), user is redirected
back to `/payment/completed` (`route('payment.finished.callback_url')`) .

> If there are additional steps you want to take upon successful payment, listen for `SuccessfulLaravelMultipayPaymentEvent`. It will be fired whenever a successful payment occurs, with its corresponding `Payment` model.

> If the `Payment` has [`metadata`](#step-1) (supplied with the payment initiation request), with a key named `completion_url`, the user will be redirected to that URL upon successful payment.

## Payment Conflict Resolution (PCR)

If for any reason, your user/customer claims that the payment they made was successful but that your platform did not reflect such successful payment, this PCR feature enables you to resolve such claims by simply calling:

```
/**
* @var bool //true if payment was successful, false otherwise
**/
$outcome = LaravelMultipay::reQueryUnsuccessfulPayment( $payment )
```

The payment will be re-resolved and the payment will be updated in the database. If the payment is successful, the `SuccessfulLaravelMultipayPaymentEvent` event will be fired, availing you the opportunity to run any domain/application-specific procedures.

## Payment Notifications (WebHooks)
Some payment handlers provide a means for sending details of successful notifications. Usually, you will need to provide the payment handler with a URL to which the details of such notification will be sent. Should you need this feature, the notification URL is `/payment/completed/notify`.

> If you use this payment notification URL feature, ensure that in your handler for `SuccessfulLaravelMultipayPaymentEvent`, check that you have not previously handled the event for that same payment.

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
