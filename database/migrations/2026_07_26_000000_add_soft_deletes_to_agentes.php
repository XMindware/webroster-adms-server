<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds two independent removal markers to agentes:
     *  - deleted_at            : "marked to remove locally" (soft delete). Set when
     *                            an agent disappears from the station roster.
     *  - device_removal_queued_at : "delete pushed to devices". Set when the
     *                            DATA DELETE USERINFO command has been queued to
     *                            the office's devices (the deferred, second phase).
     */
    public function up(): void
    {
        Schema::table('agentes', function (Blueprint $table) {
            $table->softDeletes();
            $table->timestamp('device_removal_queued_at')->nullable()->after('deleted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agentes', function (Blueprint $table) {
            $table->dropColumn('device_removal_queued_at');
            $table->dropSoftDeletes();
        });
    }
};
