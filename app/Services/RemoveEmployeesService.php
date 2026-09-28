<?php

namespace App\Services;

use App\Models\Device;
use App\Services\Adms\AdmsCommandService;
use App\Services\Adms\AdmsProtocol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RemoveEmployeesService
{
    protected Device $device;
    protected AdmsCommandService $commands;

    /**
     * Constructor
     *
     * Counterpart of PopulateEmployeesService: instead of pushing user data to
     * the device, it queues DATA DELETE USERINFO commands so the terminal drops
     * employees that were removed from the station.
     *
     * @author XMindware
     */
    public function __construct(Device $device, ?AdmsCommandService $commands = null)
    {
        $this->device = $device;
        $this->commands = $commands ?? app(AdmsCommandService::class);
    }

    /**
     * Queue a delete command for each supplied employee on this device.
     *
     * Returns the number of commands queued.
     */
    public function run($employees = null): int
    {
        Log::info('RemoveEmployeesService', ['job' => self::class]);

        $employees = $employees instanceof Collection
            ? $employees->values()
            : collect($employees ?? [])->values();

        if ($employees->isEmpty()) {
            Log::info('No employees to remove', ['device_id' => $this->device->id]);
            return 0;
        }

        Log::info('Employees to remove', [
            'device_id' => $this->device->id,
            'employee_count' => $employees->count(),
        ]);

        foreach ($employees as $employee) {
            // One DATA DELETE USERINFO per employee, keyed by PIN.
            $command = $this->commands->queue(
                $this->device,
                AdmsProtocol::deleteUserinfo($employee->idagente),
                AdmsProtocol::TYPE_USERINFO_DELETE
            );

            Log::info('Delete command created', ['command' => $command]);
        }

        return $employees->count();
    }
}
