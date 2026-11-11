<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PUSH protocol version each terminal announces in its handshake
 * (pushver=2.4.1, 3.0.0, ...). Which commands a terminal understands depends
 * on it, so a pull can skip the ones this terminal is known to refuse.
 *
 * @author XMindware
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (!Schema::hasColumn('devices', 'push_version')) {
                $table->string('push_version', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (Schema::hasColumn('devices', 'push_version')) {
                $table->dropColumn('push_version');
            }
        });
    }
};
