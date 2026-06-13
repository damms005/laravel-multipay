<?php

namespace Damms005\LaravelMultipay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;

class Subscription extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

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

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
