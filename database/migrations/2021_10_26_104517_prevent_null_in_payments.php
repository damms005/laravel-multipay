<?php

use Damms005\LaravelCashier\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class PreventNullInPayments extends Migration
{
	public function up()
	{
		Schema::table((new Payment)->getTable(), function (Blueprint $table) {
			$table->json("metadata")
				->after("completion_url")
				->default("")
				->change();
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down()
	{
		Schema::table((new Payment)->getTable(), function (Blueprint $table) {
			$table->json("metadata")
				->after("completion_url")
				->nullable();
		});
	}
}
