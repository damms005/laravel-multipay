<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('laravel-multipay.table_prefix', '');

        Schema::table("{$prefix}payments", function (Blueprint $table) {
            $table->timestamp('dispatched_at')->nullable()->after('is_success')->index();
            $table->string('payment_handler_subscription_code')->nullable()->after('processor_transaction_reference')->index();
        });
    }

    public function down(): void
    {
        $prefix = config('laravel-multipay.table_prefix', '');

        Schema::table("{$prefix}payments", function (Blueprint $table) {
            $table->dropIndex(["{$prefix}payments_dispatched_at_index"]);
            $table->dropIndex(["{$prefix}payments_payment_handler_subscription_code_index"]);
            $table->dropColumn(['dispatched_at', 'payment_handler_subscription_code']);
        });
    }
};
