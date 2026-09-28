<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * devices.modelo was never actually created.
     *
     * 2024_11_21_013744_add_oficina_data_to_devices wrapped idoficina, its
     * foreign key and modelo inside `if (!Schema::hasColumn('devices',
     * 'idoficina'))` - but that column already existed, as a varchar, from
     * 2023_07_25_021046_create_devices_table. So the block was skipped on every
     * install and modelo never appeared.
     *
     * It is not dead weight: Device::$fillable already lists it and
     * resources/views/devices/monitor.blade.php renders
     * `$device->modelo ?? 'N/A'`, so the monitor page has been showing "N/A"
     * for every device since the migration was written.
     *
     * Nullable, because this runs against tables that already hold rows - a NOT
     * NULL column with no default cannot be added to a populated table.
     */
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'modelo')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->string('modelo')->nullable()->after('idoficina');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('devices', 'modelo')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('modelo');
        });
    }
};
