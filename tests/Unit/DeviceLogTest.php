<?php

namespace Tests\Unit;

use App\Models\DeviceLog;
use PHPUnit\Framework\TestCase;

class DeviceLogTest extends TestCase
{
    /**
     * device_log.idreloj has to stay fillable: iclockController passes it to
     * DeviceLog::create(), and while it was missing from this list every row
     * silently kept the column default instead of the device's clock id.
     *
     * This file used to be called DeviceTests.php. PHPUnit only collects files
     * ending in "Test.php", so it never ran, which is how it drifted out of
     * date without anyone noticing. Renamed to match the convention.
     */
    public function test_device_log_has_correct_fillable_properties(): void
    {
        $this->assertEquals(
            ['id', 'data', 'tgl', 'sn', 'option', 'url', 'idreloj'],
            (new DeviceLog)->getFillable()
        );
    }
}
