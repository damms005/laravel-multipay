<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('model')->nullable(false)->default()->after('id');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('user_id', 'model_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('model');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->renameColumn('model_id', 'user_id');
        });
    }
};
