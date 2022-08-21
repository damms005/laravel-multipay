<?php

namespace Damms005\LaravelMultipay\Facades;

use Illuminate\Support\Facades\Facade;

class LaravelMultipay extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'laravel-multipay';
    }
}
