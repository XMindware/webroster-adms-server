<?php

namespace App\Console\Commands;

use App\Models\Oficina;
use App\Services\SyncStationEmployeesService;
use Illuminate\Console\Command;

class SyncStationEmployees extends Command
{
    protected $signature = 'employees:sync-stations';

    protected $description = 'Pull employees from stations and queue only new employees for their devices.';

    public function handle(SyncStationEmployeesService $service): int
    {
        $offices = Oficina::orderBy('idempresa')->orderBy('idoficina')->get();

        foreach ($offices as $office) {
            $result = $service->syncOffice($office);

            if ($result['failed']) {
                $this->error("Failed office {$office->idempresa}/{$office->idoficina}");
                continue;
            }

            $this->info(
                "Office {$office->idempresa}/{$office->idoficina}: ".
                "pulled {$result['pulled']}, new {$result['created']}, ".
                "updated {$result['updated']}, restored {$result['restored']}, ".
                "removed {$result['removed']}, queued {$result['commands']}"
            );
        }

        return self::SUCCESS;
    }
}
