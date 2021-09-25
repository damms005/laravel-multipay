# An opinionated Laravel package for handling payments in a Laravel package 💸

[![Latest Version on Packagist](https://img.shields.io/packagist/v/damms005/laravel-cashier.svg?style=flat-square)](https://packagist.org/packages/damms005/laravel-cashier)
[![GitHub Tests Action Status](https://img.shields.io/github/workflow/status/damms005/laravel-cashier/run-tests?label=tests)](https://github.com/damms005/laravel-cashier/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/workflow/status/damms005/laravel-cashier/Check%20&%20fix%20styling?label=code%20style)](https://github.com/damms005/laravel-cashier/actions?query=workflow%3A"Check+%26+fix+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/damms005/laravel-cashier.svg?style=flat-square)](https://packagist.org/packages/damms005/laravel-cashier)

Whether you want to quickly bootstrap payment processing for your Laravel applications, or you want a way to test supported payment processors, this package's got you covered.
Being opinionated, it comes with [Tailwindcss-powered](http://tailwindcss.com/) blade views, so that you can simply Plug-and-play™️.

## Installation

You can install the package via composer:

```bash
composer require damms005/laravel-cashier
```

Publish the config file with:

```bash
php artisan vendor:publish --tag=laravel-cashier-config
```

Run `php artisan migrate`

## Usage

### Test drive 🏎️

Want to take things for a spin? simply visit `/payment/test-drive` .
For [Paystack](https://paystack.com), ensure to set `paystack_secret_key` key in the `laravel-cashier.php` config file that you published previously at installation. You can get your key from your [settings page](https://dashboard.paystack.co/#/settings/developer).

#### Needed Third-party Integrations:

Flutterwave: https://dashboard.flutterwave.com/dashboard/settings/apis
Corresponding environmental variables:

```
FLW_PUBLIC_KEY=FLWPUBK-xxxxxxxxxxxxxxxxxxxxx-X
FLW_SECRET_KEY=FLWSECK-xxxxxxxxxxxxxxxxxxxxx-X
FLW_SECRET_HASH='My_lovelysite123'
```

Paystack: https://dashboard.paystack.co/#/settings/developer
Corresponding environmental variables:

```
PAYSTACK_SECRET_KEY=FLWPUBK-xxxxxxxxxxxxxxxxxxxxx-X
```

### Usage in Laravel projects

#### Step 1

Send a `POST` request to `/payment/details/confirm`.
Check the [InitiatePaymentRequest](src/Http/Requests/InitiatePaymentRequest.php#L28) form request to know the values you are to post to this endpoint. (tip: you can also check [views/test-drive/pay.blade.php](`views/test-drive/pay.blade.php`))

#### Step 2

Upon user confirmation of transaction, user is redirected to the appropriate payment handler's gateway.

#### Step 3

When user is done with the transaction on the payment handler's end (either successfully paid, or declined transaction), user is redirected
back to `/api/payment/completed`.

If there are additional steps you want to take upon successful payment, listen for `SuccessfulLaravelCahierPaymentEvent`. It will be fired whenever a successful payment occurs, with its corresponding `Payment` model.

## Supported Payment Handlers

Currently, this package supports the following online payment processors/handlers

-   [Paystack](https://paystack.com)
-   [Flutterwave](https://flutterwave.com)
-   [Interswitch](https://www.interswitchgroup.com)
-   [UnifiedPayments](https://unifiedpayments.com)

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

## Roadmap

-   Use Tailwindcss to apply minimal styling
