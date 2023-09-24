<?php

namespace Damms005\LaravelMultipay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected const TABLE_NAME = 'subscriptions';

    protected $casts = [
        'next_payment_due_date' => 'datetime',
        'metadata' => AsArrayObject::class,
    ];

    public function getTable(): string
    {
        $userDefinedTablePrefix = config('laravel-multipay.table_prefix');

        if ($userDefinedTablePrefix) {
            return $userDefinedTablePrefix . self::TABLE_NAME;
        }

        return self::TABLE_NAME;
    }

    public function paymentPlan()
    {
        return $this->belongsTo(PaymentPlan::class);
    }
}
