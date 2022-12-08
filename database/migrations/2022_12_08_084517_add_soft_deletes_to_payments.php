<?php

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up()
    {
        Schema::table((new Payment())->getTable(), function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};
