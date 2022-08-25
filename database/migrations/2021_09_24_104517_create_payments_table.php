<?php

use Damms005\LaravelMultipay\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create((new Payment())->getTable(), function (Blueprint $table) {
            $table->id();
            $table->integer("user_id");
            $table->string("product_id")->nullable();
            $table->bigInteger("original_amount_displayed_to_user");
            $table->string("transaction_currency"); //in ISO-4217 format
            $table->string("transaction_description");
            $table->string("transaction_reference");
            $table->string("payment_processor_name");
            $table->integer("pay_item_id")->nullable();
            $table->string("processor_transaction_reference")->nullable();
            $table->string("processor_returned_response_code")->nullable();
            $table->string("processor_returned_card_number")->nullable();
            $table->text("processor_returned_response_description")->nullable();
            $table->string("processor_returned_amount")->nullable();
            $table->timestamp("processor_returned_transaction_date")->nullable();
            $table->string("customer_checkout_ip_address")->nullable();
            $table->boolean("is_success")->nullable();
            $table->integer("retries_count")->nullable();
            $table->string("completion_url")
                ->comment("the url to redirect user after all is completed (notwithstanding if success/failure transaction. Just a terminal endpoint)")
                ->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists((new Payment())->getTable());
    }
}
