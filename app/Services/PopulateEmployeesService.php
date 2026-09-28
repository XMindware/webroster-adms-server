<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Device;
use App\Services\Adms\AdmsCommandService;
use App\Services\Adms\AdmsProtocol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PopulateEmployeesService
{
    protected Device $device;
    protected AdmsCommandService $commands;

    /**
     * Bind the service to the device whose roster is being pushed.
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PopulateEmployeesService.php
     */
    public function __construct(Device $device, ?AdmsCommandService $commands = null)
    {
        $this->device = $device;
        $this->commands = $commands ?? app(AdmsCommandService::class);
    }

    /**
     * Queue the office's employees into device_commands, ready for the terminal
     * to collect on its next poll.
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PopulateEmployeesService.php
     */
    public function run($employees = null): int
    {
        Log::info('PopulateEmployeesService', ['job' => self::class]);

        $employees = $employees instanceof Collection
            ? $employees->values()
            : collect($employees ?? Agente::where('idempresa', $this->device->idempresa)
                ->where('idoficina', $this->device->idoficina)
                ->get())->values();

        if ($employees->isEmpty()) {
            Log::info('No employees to populate', ['device_id' => $this->device->id]);

            return 0;
        }

        Log::info('Employees retrieved', [
            'device_id' => $this->device->id,
            'employee_count' => $employees->count(),
        ]);

        foreach ($employees as $employee) {
            // One DATA UPDATE USERINFO per employee. The terminal upserts on
            // PIN, so this covers both a new hire and a renamed one.
            $command = $this->commands->queue(
                $this->device,
                AdmsProtocol::updateUserinfo($employee->idagente, $employee->fullname),
                AdmsProtocol::TYPE_USERINFO_UPSERT
            );

            Log::info('Command created', ['command' => $command]);
        }

        return $employees->count();
    }
}
