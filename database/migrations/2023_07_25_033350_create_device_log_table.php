<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDeviceLogTable extends Migration
{
    public function up()
    {
        Schema::create('device_log', function (Blueprint $table) {
            $table->id();
            $table->text('data');
            $table->date('tgl')->nullable();
            $table->string('sn');
            $table->string('option')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down()
    {
        Schema::dropIfExists('device_log');
    }
}
