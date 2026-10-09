<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Newest punch per terminal" lookup for the WebRoster device status API: one
 * max(id) per serial number for the whole fleet, in a single query.
 *
 * attendances_sn_created_at_timestamp_index cannot serve it even though it
 * also leads with `sn`. Its second column is a *range* column, so the index
 * cannot be walked in id order and MySQL has to read every row of each
 * device's history to find the newest one - on a terminal that has been
 * punching for years that is hundreds of thousands of index entries per
 * device, on every poll. Leading with `id` instead lets the engine take the
 * last entry of each sn range directly.
 *
 * The guard is the same driver-aware one as 2026_09_23_100000, kept as a copy
 * because that migration has already run in production and editing it would
 * change nothing there.
 *
 * ── Deploying this on a large table ─────────────────────────────────────────
 * ADD INDEX runs INPLACE on MySQL 5.7+/8.0 with InnoDB, so `php artisan
 * migrate` is safe. If the server is older, or you would rather build it
 * off-peak, run this by hand and then mark the migration applied:
 *
 *   ALTER TABLE attendances ADD INDEX attendances_sn_id_index (sn, id),
 *     ALGORITHM=INPLACE, LOCK=NONE;
 *
 *   php artisan migrate --force   # skips, because the index now exists
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('attendances_sn_id_index', ['sn', 'id']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('attendances') || !$this->hasIndex('attendances_sn_id_index')) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_sn_id_index');
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndex(string $name, array $columns): void
    {
        if (!Schema::hasTable('attendances') || $this->hasIndex($name)) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table) use ($columns, $name) {
            $table->index($columns, $name);
        });
    }

    /**
     * Does an index with this name exist on `attendances`?
     *
     * Must work on both drivers: `SHOW INDEX FROM` is MySQL-only and throws on
     * SQLite, so a driver-blind guard would silently report "absent" and then
     * fail the re-create with "index already exists".
     */
    private function hasIndex(string $name): bool
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'sqlite') {
                return collect(DB::select("PRAGMA index_list('attendances')"))
                    ->contains(fn ($index) => ($index->name ?? null) === $name);
            }

            return collect(DB::select(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 LIMIT 1',
                ['attendances', $name]
            ))->isNotEmpty();
        } catch (\Throwable $e) {
            // Unknown driver: assume present so we never blind-create. A
            // missing index is recoverable; a failed migration is not.
            return true;
        }
    }
};
