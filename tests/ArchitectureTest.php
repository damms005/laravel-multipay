<?php

describe('architecture tests', function () {
    arch()
        ->expect('Damms005\LaravelMultipay')
        ->not->toUse(['die', 'dd', 'dump']);

    arch()->preset()->php();
    arch()->preset()->laravel()->ignoring('Damms005\LaravelMultipay\LaravelMultipayServiceProvider');
    arch()->preset()->security()->ignoring('md5');
});
