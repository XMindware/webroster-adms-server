<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * agentes.idagente was a signed INT (max 2,147,483,647), but station employee
 * IDs can be 10+ digits (e.g. 9984116568), which made agentes/runpull fail with
 * "Numeric value out of range". Widen it to BIGINT.
 *
 * Raw ALTER because doctrine/dbal is not installed (needed for ->change() on
 * Laravel 10). SQLite (tests) INTEGER is already 64-bit, so it is skipped.
 *
 * @author XMindware
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `agentes` MODIFY `idagente` BIGINT NOT NULL');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `agentes` MODIFY `idagente` INT NOT NULL');
        }
    }
};
