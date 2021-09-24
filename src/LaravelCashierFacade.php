<?php

namespace Damms005\LaravelCashier;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Damms005\LaravelCashier\LaravelCashier
 */
class LaravelCashierFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-cashier';
    }
}
