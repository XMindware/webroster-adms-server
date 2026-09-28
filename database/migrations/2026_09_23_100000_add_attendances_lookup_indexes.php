<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * attendances shipped with no index at all beyond the primary key.
 *
 * Every clock-discrepancy check runs
 *
 *   WHERE sn = ? AND created_at BETWEEN ? AND ? AND timestamp BETWEEN ? AND ?
 *
 * and that method is called on *every* terminal poll (~30s). Against a
 * multi-million row table each of those calls was a full table scan whose
 * entire result set was materialised through PDO::fetchAll() - which is what
 * exhausted PHP's 128 MB limit and killed /iclock/getrequest in production.
 *
 * The composite column order matters: `sn` is an equality predicate, so it
 * belongs first; the two range columns follow. MySQL can then seek straight to
 * the device's rows and walk the range instead of scanning the table.
 *
 * ── Deploying this on a large table ─────────────────────────────────────────
 * On MySQL 5.7+/8.0 with InnoDB, ADD INDEX normally runs INPLACE without
 * blocking reads or writes, so `php artisan migrate` is safe. If the server is
 * older, or if you would rather build it off-peak, run these by hand and then
 * mark the migration as applied:
 *
 *   ALTER TABLE attendances ADD INDEX attendances_sn_created_at_timestamp_index
 *     (sn, created_at, timestamp), ALGORITHM=INPLACE, LOCK=NONE;
 *
 *   php artisan migrate --force   # will skip, because the index now exists
 *
 * Both indexes are created defensively: production already carries schema
 * drift (see the missing index entirely, and the `oficinas` FK mismatch), so a
 * half-applied migration must be re-runnable rather than fatal.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The workhorse: serves getTimezoneDiscrepancyCount()/hayDesfasesHoy()
        // and every per-device attendance listing.
        $this->addIndex(
            'attendances_sn_created_at_timestamp_index',
            ['sn', 'created_at', 'timestamp']
        );

        // The sync command reads `WHERE response_uniqueid IS NULL`. Left out of
        // the composite above because a nullable column in the middle of an
        // index degrades the range scan for the far more common query.
        $this->addIndex(
            'attendances_response_uniqueid_index',
            ['response_uniqueid']
        );
    }

    public function down(): void
    {
        $this->dropIndex('attendances_sn_created_at_timestamp_index');
        $this->dropIndex('attendances_response_uniqueid_index');
    }

    /**
     * Add an index unless one with that name is already present.
     *
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

    private function dropIndex(string $name): void
    {
        if (!Schema::hasTable('attendances') || !$this->hasIndex($name)) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table) use ($name) {
            $table->dropIndex($name);
        });
    }

    /**
     * Does an index with this name exist on `attendances`?
     *
     * Must work on both drivers: `SHOW INDEX FROM` is MySQL-only and throws on
     * SQLite, so a driver-blind guard would silently report "absent" and then
     * fail the re-create with "index already exists" - which is exactly what
     * happens when production already carries the index and someone re-runs
     * migrations.
     */
    private function hasIndex(string $name): bool
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'sqlite') {
                return collect(DB::select("PRAGMA index_list('attendances')"))
                    ->contains(fn ($index) => ($index->name ?? null) === $name);
            }

            // MySQL / MariaDB. INFORMATION_SCHEMA is used rather than SHOW INDEX
            // so the name can be filtered by the database engine itself.
            return collect(DB::select(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 LIMIT 1',
                ['attendances', $name]
            ))->isNotEmpty();
        } catch (\Throwable $e) {
            // Unknown driver: assume present so we never blind-create. A missing
            // index is recoverable; a failed migration mid-deploy is not.
            return true;
        }
    }
};
