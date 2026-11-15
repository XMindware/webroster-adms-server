<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RemoveEmployeesService
{
    protected $device;
    protected $commandIdService;

    /**
     * Constructor
     *
     * Counterpart of PopulateEmployeesService: instead of pushing user data to
     * the device, it queues DATA DELETE USERINFO commands so the terminal drops
     * employees that were removed from the station.
     *
     * @author XMindware
     */
    public function __construct(Device $device, ?CommandIdService $commandIdService = null)
    {
        $this->device = $device;
        $this->commandIdService = $commandIdService ?? app(CommandIdService::class);
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
            $cmdId = $this->commandIdService->getNextCmdId();

            $command = $this->device->commands()->create([
                'command' => $cmdId,
                'device_id' => $this->device->id,
                'data' => $this->deleteEmployee($employee, $cmdId),
            ]);

            Log::info('Delete command created', ['command' => $command]);
        }

        return $employees->count();
    }

    /**
     * Format the ADMS command that removes a user (and their biometric
     * templates) from the terminal by PIN.
     */
    protected function deleteEmployee($employee, $CmdId): string
    {
        return "C:{$CmdId}:DATA DELETE USERINFO PIN={$employee->idagente}";
    }
}
