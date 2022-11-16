<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up()
    {
        Schema::table((new Payment())->getTable(), function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
