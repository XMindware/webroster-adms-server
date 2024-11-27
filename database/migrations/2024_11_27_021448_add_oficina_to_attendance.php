<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // add missing index idoficina in oficinas table
        Schema::table('oficinas', function (Blueprint $table) {
            if (!Schema::hasColumn('oficinas', 'idoficina')) {
                $table->idoficina('idoficina')->primary();
            }
        });
        Schema::table('attendances', function (Blueprint $table) {
            if (!Schema::hasColumn('attendances', 'idoficina')) {
                $table->unsignedSmallInteger('idoficina')->nullable();
                $table->index('idoficina');
                $table->foreign('idoficina')->references('idoficina')->on('oficinas');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $indexName = 'attendances_idoficina_index'; // Replace with actual name
            $indexes = DB::select("SHOW INDEX FROM attendances WHERE Key_name = ?", [$indexName]);

            if (!empty($indexes)) {
                $table->dropIndex([$indexName]);
            }
            if(Schema::hasColumn('attendances', 'idoficina')) {
                $table->dropColumn('idoficina');
            }
        });
    }
};
