<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            // $table->integer('reservation_number')->unique()->comment('Unique reservation number');
            $table->integer('client_id')->comment('ID of the client making the reservation');
            $table->date('start_date')->default(today())->comment('the reservation start date');
            $table->integer('nights')->default(1)->comment('the reservation nights');
            $table->date('expire_date')->comment('the reservation expire date');
            $table->tinyInteger("reservation_type")->default(0)->in_array([0, 1])->comment("0=> Single 1=> collective");
            $table->tinyInteger("reservation_status")->default(0)->in_array([0, 1])->comment("0=> Unconfirmed 1=> Confirmed");
            $table->integer('stay_reason_id');
            $table->integer('reservation_source_id');
            $table->tinyInteger("rent_type")->default(0)->in_array([0, 1, 2])->comment("0=>daily 1=>monthly 2=>annual");
            $table->double('base_price')->comment('the default room price');
            $table->double('discount')->default(0)->comment('the discount granted to the reservation');
            $table->double('extras')->default(0)->comment('the total of any additional values');
            $table->double('penalties')->default(0)->comment('the total of the penalties if any');
            $table->double('subtotal')->comment('the subtotal of reservation');
            $table->double('taxes')->comment('the total of taxes');
            $table->double('total')->comment('the final total of reservetion');
            $table->tinyInteger('logedin')->comment("0=> Guest is Logged In 1=>Guest is Not Logged In");
            $table->date('login_time')->comment('the time the guest actually loged in at');
            $table->integer('user_id')->comment('the user who make the reservation');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
