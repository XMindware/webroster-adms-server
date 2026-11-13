<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per snapshot taken of a terminal.
 *
 * The file itself lives on the `local` disk (storage/app/device-backups); this
 * table is the index of what exists, how big it is and what went into it, so
 * the list page never has to open a file to describe it.
 *
 * device_id is deliberately nullable and deliberately NOT a foreign key: the
 * snapshot is most valuable exactly when the terminal it came from has been
 * deleted or replaced, and a cascade would take the row - and with it the only
 * pointer to the file - away with the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_backups', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('device_id')->nullable()->index();

            // Denormalised so a snapshot can still be identified after the
            // device row is gone.
            $table->string('serial_number')->index();
            $table->string('device_name')->nullable();

            // Relative to the `local` disk, e.g. device-backups/SN-1-20261113-113000.json
            $table->string('file_name');

            $table->unsignedBigInteger('size_bytes')->default(0);

            // What the snapshot holds, counted while it was written.
            $table->unsignedInteger('employees_count')->default(0);
            $table->unsignedInteger('fingerprints_count')->default(0);
            // Face templates and comparison photos combined - both are one
            // enrolment to an operator, and the JSON keeps them apart.
            $table->unsignedInteger('faces_count')->default(0);
            $table->unsignedInteger('attendances_count')->default(0);

            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_backups');
    }
};
