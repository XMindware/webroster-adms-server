<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Device;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PopulateEmployeesService
{
    protected $device;
    protected $commandIdService;

    /**
     * Constructor
     *
     * Inisialisasi service dengan model Device
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PopulateEmployeesService.php
     */
    public function __construct(Device $device, ?CommandIdService $commandIdService = null)
    {
        $this->device = $device;
        $this->commandIdService = $commandIdService ?? app(CommandIdService::class);
    }

    /**
     * Run Service
     *
     * Menjalankan proses populasi data karyawan ke tabel commands untuk disinkronkan ke mesin
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
            $cmdId = $this->commandIdService->getNextCmdId();

            // create a command to populate the employee
            $command = $this->device->commands()->create([
                'command' => $cmdId,
                'device_id' => $this->device->id,
                'data' => $this->updateEmployee($employee, $cmdId)
            ]);
            Log::info('Command created', ['command' => $command]);
        }

        return $employees->count();
    }

    /**
     * Update Employee Data Format
     *
     * Memformat string perintah update data user sesuai protokol ADMS
     *
     * @author mdestafadilah
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PopulateEmployeesService.php
     */
    protected function updateEmployee($employee, $CmdId)
    {
        return "C:{$CmdId}:DATA UPDATE USERINFO PIN={$employee->idagente}	Name={$employee->fullname}	Passwd=	Card=	Grp=1	TZ=0000000100000000	Pri=0	Category=0";
    }
}
