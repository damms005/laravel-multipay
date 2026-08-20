<?php

namespace Damms005\LaravelMultipay\Enums;

enum ChargeKind: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case OneOff = 'one_off';
}
