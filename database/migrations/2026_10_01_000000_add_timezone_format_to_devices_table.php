<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * devices.timezone_format decides whether the handshake sends a TimeZone
     * line, and in what shape.
     *
     * The field is an hour offset ("7"), not an IANA identifier, so the office
     * name could never be sent as-is - which is why the handshake stopped
     * sending the line at all (see iclockController::handshakeOptions()). Some
     * firmware needs the offset though, and a terminal that is never told the
     * timezone keeps drifting until the server has to order a clock correction
     * on every poll.
     *
     * Per device, because the fleet is not uniform: what one terminal parses
     * happily makes another reject the whole options block, and a device that
     * rejects it never settles into a normal polling rhythm.
     *
     *   null (default) - no TimeZone line at all. The behaviour every device
     *                    in production has today, so nothing changes on
     *                    upgrade.
     *   'hours'        - offset in hours, e.g. 7 for Asia/Jakarta, 5.5 for
     *                    Asia/Kolkata.
     *   'minutes'      - offset in minutes, e.g. 420.
     *
     * Nullable, because this runs against a populated table: a NOT NULL column
     * with no default cannot be added to one.
     */
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'timezone_format')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->string('timezone_format', 10)->nullable()->after('modelo');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('devices', 'timezone_format')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('timezone_format');
        });
    }
};
