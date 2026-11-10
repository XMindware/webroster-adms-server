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
            $table->unsignedSmallInteger('idoficina')->index();
        }
        else{
            $table->index('idoficina');
        }
    });

    // Add idoficina to attendances table and create foreign key relationship
    Schema::table('attendances', function (Blueprint $table) {
        if (!Schema::hasColumn('attendances', 'idoficina')) {
            $table->unsignedSmallInteger('idoficina')->nullable();

            // oficinas.idoficina is indexed but not unique. MySQL accepts a
            // foreign key to it; SQLite rejects every write to the child table
            // ("foreign key mismatch"). Laravel 10 silently skipped foreign
            // keys added to an existing SQLite table, Laravel 11+ creates
            // them, so keep SQLite (the test database) as it always was.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->foreign('idoficina')->references('idoficina')->on('oficinas')->onDelete('cascade');
            }
        }
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'idoficina')) {
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->dropForeign(['idoficina']);
                }
                $table->dropColumn('idoficina');
            }
            if(Schema::hasColumn('attendances', 'idoficina')) {
                $table->dropColumn('idoficina');
            }
        });
    }
};
