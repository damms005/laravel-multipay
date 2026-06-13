<?php

use Damms005\LaravelMultipay\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::table((new Subscription())->getTable(), function (Blueprint $table) {
            $table->string('payment_handler_subscription_code')->nullable()->after('metadata');
            $table->string('payment_handler_email_token')->nullable()->after('payment_handler_subscription_code');
            $table->string('status')->default(Subscription::STATUS_ACTIVE)->after('payment_handler_email_token');
        });
    }

    public function down()
    {
        Schema::table((new Subscription())->getTable(), function (Blueprint $table) {
            $table->dropColumn([
                'payment_handler_subscription_code',
                'payment_handler_email_token',
                'status',
            ]);
        });
    }
};
