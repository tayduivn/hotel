<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRoomDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('room_details', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('room_id');
            $table->string('name', 191);
            $table->integer('price');
            $table->integer('sale_price')->nullable();
            $table->text('short_description');
            $table->longText('description');
            $table->integer('lang_id');
            $table->integer('lang_parent_id')->default(0);
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
        Schema::dropIfExists('room_details');
    }
}
