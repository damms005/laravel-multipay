<?php

namespace Damms005\LaravelMultipay\Models;

use Damms005\LaravelMultipay\Contracts\PaymentHandlerInterface;
use Damms005\LaravelMultipay\Services\PaymentHandlers\BasePaymentHandler;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property integer $user_id
 * @property string $product_id
 * @property integer $original_amount_displayed_to_user
 * @property string $transaction_currency
 * @property string $transaction_description
 * @property string $transaction_reference
 * @property string $payment_processor_name
 * @property integer $pay_item_id
 * @property string $processor_transaction_reference
 * @property string $processor_returned_response_code
 * @property string $processor_returned_card_number
 * @property ?string $processor_returned_response_description
 * @property string $processor_returned_amount
 * @property string $processor_returned_transaction_date
 * @property string $customer_checkout_ip_address
 * @property boolean|null $is_success
 * @property integer $retries_count
 * @property string $completion_url
 * @property array $metadata

 * @property User $user
 *
 */
class Payment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => AsArrayObject::class,
    ];

    protected const TABLE_NAME = 'payments';
    public const KOBO_TO_NAIRA = 100;

    public function getTable(): string
    {
        $userDefinedTablePrefix = config('laravel-multipay.table_prefix');

        if ($userDefinedTablePrefix) {
            return $userDefinedTablePrefix . self::TABLE_NAME;
        }

        return self::TABLE_NAME;
    }

    public function user()
    {
        return $this->belongsTo(config('laravel-multipay.user_model_fqcn'));
    }

    public function scopeSuccessful($query)
    {
        $query->where('is_success', 1);
    }

    /**
     * Gets the payment provider/handler for this payment
     */
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
            return ((float) $this->processor_returned_amount) / 100;
        }

        return $this->processor_returned_amount;
    }
}
