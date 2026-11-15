<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag queued commands so the fingerprint pull/push pipeline can tell its own
 * commands apart from user population, restarts, etc. and so the device's
 * acknowledgement on /iclock/devicecmd can be attributed correctly.
 *
 * @author XMindware
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            if (!Schema::hasColumn('device_commands', 'type')) {
                $table->string('type', 32)->nullable()->index()->after('command');
            }
            if (!Schema::hasColumn('device_commands', 'reference')) {
                // e.g. "PIN:1234/FID:0" so an ack can be traced to an employee finger
                $table->string('reference')->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table) {
            if (Schema::hasColumn('device_commands', 'reference')) {
                $table->dropColumn('reference');
            }
            if (Schema::hasColumn('device_commands', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
