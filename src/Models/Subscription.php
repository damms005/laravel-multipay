<?php

namespace Damms005\LaravelMultipay\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Support\Facades\Schema;

class Subscription extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NON_RENEWING = 'non_renewing';

    protected $guarded = ['id'];

    protected const TABLE_NAME = 'subscriptions';

    protected $casts = [
        'next_payment_due_date' => 'datetime',
        'metadata' => AsArrayObject::class,
        'quantity' => 'integer',
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

    /**
     * Rows of one provider customer: by the customer code that subscription.create
     * stores, else by the email of the row's user. Matches no row when the charge
     * names no customer, so a charge is never credited to another user.
     */
    public function scopeForCustomer(Builder $query, ?string $customerCode, ?string $customerEmail): Builder
    {
        $userIds = self::userIdsWithEmail($customerEmail);

        return $query->where(function (Builder $query) use ($customerCode, $userIds) {
            $query->whereRaw('1 = 0');

            if (filled($customerCode)) {
                $query->orWhere('metadata->customer_code', $customerCode);
            }

            if ($userIds !== []) {
                $query->orWhereIn('user_id', $userIds);
            }
        });
    }

    /**
     * @return array<int, mixed>
     */
    protected static function userIdsWithEmail(?string $email): array
    {
        $userModelClass = config('laravel-multipay.user_model_fqcn');
        $emailColumn = config('laravel-multipay.user_model_properties.email', 'email');

        if (blank($email) || ! is_string($userModelClass) || ! class_exists($userModelClass)) {
            return [];
        }

        $userModel = new $userModelClass();

        // The email property can be an accessor. Only a real column can be queried.
        if (! Schema::connection($userModel->getConnectionName())->hasColumn($userModel->getTable(), $emailColumn)) {
            return [];
        }

        return $userModelClass::query()
            ->whereRaw('lower(' . $userModel->getConnection()->getQueryGrammar()->wrap($emailColumn) . ') = ?', [strtolower($email)])
            ->pluck(config('laravel-multipay.user_model_owner_key', 'id'))
            ->all();
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

    public function isNonRenewing(): bool
    {
        return $this->status === self::STATUS_NON_RENEWING;
    }
}
