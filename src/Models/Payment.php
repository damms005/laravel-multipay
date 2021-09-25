<?php

namespace Damms005\LaravelCashier\Models;

use Damms005\LaravelCashier\Contracts\PaymentHandlerInterface;
use Damms005\LaravelCashier\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @mixin IdeHelperPayment
 */
class Payment extends Model
{
	use HasFactory;

	protected $guarded = ['id'];

	protected const TABLE_NAME = 'payments';
	public const KOBO_TO_NAIRA = 100;

	public function getTable(): string
	{
		$userDefinedTablePrefix = config('laravel-cashier.table_prefix');

		if ($userDefinedTablePrefix) {
			return $userDefinedTablePrefix . self::TABLE_NAME;
		}

		return self::TABLE_NAME;
	}

	public function user()
	{
		return $this->belongsTo(\App\User::class);
	}

	public function scopeSuccessful($query)
	{
		$query->where('is_success', 1);
	}

	public function getPaymentProvider(): BasePaymentHandler | PaymentHandlerInterface
	{
		$handler = Str::of(BasePaymentHandler::class)
			->beforeLast("\\")
			->append("\\")
			->append($this->payment_processor_name)
            ->__toString();

		return new $handler();
	}

	public function getAmountInNaira()
	{
		if ($this->processor_returned_amount > 0) {
			return $this->processor_returned_amount / 100;
		}

		return $this->processor_returned_amount;
	}
}
