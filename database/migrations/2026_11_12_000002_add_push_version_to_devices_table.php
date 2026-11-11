<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PUSH protocol version each terminal announces in its handshake
 * (pushver=2.4.1, 3.0.0, ...).
 *
 * Which commands a terminal understands depends on it: PUSH 2.x refuses the
 * unified-template table queries with Return=-1004 and has no face query at
 * all, so a pull that skips them leaves no failed commands behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'push_version')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->string('push_version', 16)->nullable()->after('modelo');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('devices', 'push_version')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('push_version');
        });
    }
};
