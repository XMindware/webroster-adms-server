<?php

namespace Tests\Unit;

use App\Models\Device;
use PHPUnit\Framework\TestCase;

class DeviceTest extends TestCase
{
    /**
     * devices.name has to stay fillable.
     *
     * It is rendered by the device list ("Location"), the monitor and the show
     * screen, and it is written by the create and edit forms. Those forms assign
     * the attribute directly, which bypasses mass-assignment protection — so the
     * gap was invisible there and only showed up through Device::create(), where
     * the name was dropped without a word. The device list then rendered an
     * empty Location column, which is how it was found.
     */
    public function test_device_name_is_fillable(): void
    {
        $this->assertContains('name', (new Device())->getFillable());
    }
}
