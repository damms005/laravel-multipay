<?php

use Damms005\LaravelMultipay\Models\PaymentPlan;
use Damms005\LaravelMultipay\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::create((new PaymentPlan())->getTable(), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('amount');
            $table->string('interval');
            $table->string('description');
            $table->string('currency');
            $table->string('payment_handler_fqcn'); // the fully qualified class name of the payment handler
            $table->string('payment_handler_plan_id'); // the id of the plan on the payment handler's platform
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create((new Subscription())->getTable(), function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id');
            $table->bigInteger('payment_plan_id');
            $table->dateTime('next_payment_due_date');
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
