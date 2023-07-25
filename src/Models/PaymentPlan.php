<?php

namespace Damms005\LaravelMultipay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentPlan extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected const TABLE_NAME = 'payment_plans';

    public function getTable(): string
    {
        $userDefinedTablePrefix = config('laravel-multipay.table_prefix');

        if ($userDefinedTablePrefix) {
            return $userDefinedTablePrefix . self::TABLE_NAME;
        }

        return self::TABLE_NAME;
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
