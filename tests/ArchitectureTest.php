<?php

describe('architecture tests', function () {
    arch(null)
        ->expect('Damms005\LaravelMultipay')
        ->not->toUse(['die', 'dd', 'dump']);

    arch(null)->preset()->php();
    arch(null)->preset()->laravel()->ignoring('Damms005\LaravelMultipay\LaravelMultipayServiceProvider');
    arch(null)->preset()->security()->ignoring('md5');
});
