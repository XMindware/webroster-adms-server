<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2: a device can be switched off without deleting it. With
 * ADMS_DEVICE_AUTH=enforce, /iclock/* refuses disabled serials.
 * Existing devices stay enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'enabled')) {
                $table->boolean('enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'enabled')) {
                $table->dropColumn('enabled');
            }
        });
    }
};
