<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Columns the application reads but that no migration ever created.
 *
 * Both of these were found by reading the migrations rather than by a failure:
 * the code that uses them degrades quietly (a page shows "N/A", a delivery goes
 * out unsigned) instead of erroring.
 */
class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Device::$fillable lists modelo and devices/monitor.blade.php renders
     * `$device->modelo ?? 'N/A'`, so a missing column silently means "N/A" for
     * every device. 2024_11_21_013744_add_oficina_data_to_devices was supposed
     * to create it and never did - 2026_09_23_000020 does.
     */
    public function test_devices_has_a_modelo_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('devices', 'modelo'),
            'devices.modelo must exist - the monitor page renders it'
        );
    }

    public function test_webhooks_has_a_secret_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('webhooks', 'secret'),
            'webhooks.secret must exist - deliveries are signed with it'
        );
    }

    /**
     * attendances shipped with no index beyond the primary key, so the
     * per-poll clock-discrepancy check was a full table scan whose result set
     * was materialised through PDO::fetchAll(). On production that exhausted
     * PHP's 128 MB limit and killed /iclock/getrequest every ~8 minutes.
     *
     * Column order matters: `sn` is the equality predicate and must come first,
     * with the two range columns after it.
     */
    public function test_attendances_has_the_lookup_indexes(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('attendances')"))
            ->pluck('name')
            ->all();

        $this->assertContains(
            'attendances_sn_created_at_timestamp_index',
            $indexes,
            'the per-device, per-day attendance lookup must be indexed'
        );

        $columns = collect(DB::select("PRAGMA index_info('attendances_sn_created_at_timestamp_index')"))
            ->sortBy('seqno')
            ->pluck('name')
            ->all();

        $this->assertSame(
            ['sn', 'created_at', 'timestamp'],
            $columns,
            'sn must lead the index - it is the equality predicate'
        );

        $this->assertContains(
            'attendances_response_uniqueid_index',
            $indexes,
            'api:sincronizeAttendance filters on response_uniqueid'
        );
    }
}
