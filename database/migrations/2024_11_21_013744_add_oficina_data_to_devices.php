<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration originally wrapped idoficina, its foreign key AND modelo
     * in a single `if (!Schema::hasColumn('devices', 'idoficina'))` guard.
     * idoficina already existed here - as a varchar, from
     * 2023_07_25_021046_create_devices_table - so the whole block was skipped
     * and modelo was never created. Run
     * 2026_09_23_000020_add_modelo_to_devices for that.
     *
     * Each column now has its own guard, so a fresh install gets whatever is
     * actually missing instead of nothing at all. down() is guarded for the
     * same reason: it used to drop columns that may not exist, which made
     * migrate:rollback fail on any database where this block had been skipped.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'idoficina')) {
                $table->unsignedSmallInteger('idoficina')->nullable();
                $table->foreign('idoficina')->references('idoficina')->on('oficinas');
            }

            if (!Schema::hasColumn('devices', 'modelo')) {
                $table->string('modelo')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'modelo')) {
                $table->dropColumn('modelo');
            }

            if (Schema::hasColumn('devices', 'idoficina')) {
                $table->dropForeign(['idoficina']);
                $table->dropColumn('idoficina');
            }
        });
    }
};
