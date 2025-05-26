<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Device;
use Carbon\Carbon;

class CheckDeviceOnlineStatus extends Command
{
    protected $signature = 'devices:check-offline-status';
    protected $description = 'Send alert if any device has been offline for more than 3 minutes';

    public function handle()
    {
        $threshold = now()->subMinutes(3);

        $devices = Device::where('online', false)
            ->where('last_online_at', '<', $threshold)
            ->get();

        foreach ($devices as $device) {
            $message = "🔌 Device `{$device->name}` (ID: {$device->id}) has been offline since {$device->last_online_at}.";

            Log::channel('slack')->warning($message);
            $this->info($message);
        }

        return 0;
    }
}

