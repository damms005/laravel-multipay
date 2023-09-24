<?php

use Damms005\LaravelMultipay\Models\PaymentPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::table((new PaymentPlan())->getTable(), function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('next_payment_due_date');
        });
    }
};
