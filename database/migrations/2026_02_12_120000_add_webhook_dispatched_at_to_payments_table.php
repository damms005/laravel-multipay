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
            $table->timestamp('webhook_dispatched_at')->nullable()->after('deleted_at');
        });
    }

    public function down(): void
    {
        $prefix = config('laravel-multipay.table_prefix', '');

        Schema::table("{$prefix}payments", function (Blueprint $table) {
            $table->dropColumn('webhook_dispatched_at');
        });
    }
};
