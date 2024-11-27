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
    // Add idoficina as primary key in oficinas table
    Schema::table('oficinas', function (Blueprint $table) {
        if (!Schema::hasColumn('oficinas', 'idoficina')) {
            $table->unsignedSmallInteger('idoficina')->primary();
        }
    });

    // Add idoficina to attendances table and create foreign key relationship
    Schema::table('attendances', function (Blueprint $table) {
        if (!Schema::hasColumn('attendances', 'idoficina')) {
            $table->unsignedSmallInteger('idoficina')->nullable();
        }

        // Add index and foreign key if not already existing
        if (!Schema::hasIndex('attendances', 'idoficina')) {
            $table->index('idoficina');
        }

        // Ensure foreign key constraint
        $table->foreign('idoficina')
            ->references('idoficina')
            ->on('oficinas')
            ->onDelete('cascade'); // Optional: Specify behavior on delete
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
