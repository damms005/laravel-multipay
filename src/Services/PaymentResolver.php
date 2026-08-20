<?php

namespace Damms005\LaravelMultipay\Services;

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

class PaymentResolver
{
    public static function model(): string
    {
        return config('laravel-multipay.payment_model', Payment::class);
    }

    public static function newQuery(): Builder
    {
        $model = static::model();

        return $model::query();
    }
}
