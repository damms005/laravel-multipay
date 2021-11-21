<?php

namespace Damms005\LaravelCashier\Facades;

use Illuminate\Support\Facades\Facade;

class LaravelCashier extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-cashier';
    }
}
