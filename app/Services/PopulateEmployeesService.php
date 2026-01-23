<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PopulateEmployeesService
{
    protected $device;

    /**
     * Constructor
     *
     * Inisialisasi service dengan model Device
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PopulateEmployeesService.php
     */
    public function __construct(Device $device)
    {
        $this->device = $device;
    }

    /**
     * Run Service
     *
     * Menjalankan proses populasi data karyawan ke tabel commands untuk disinkronkan ke mesin
     *
     * @author XMindware
     * @link https://github.com/hallobayi/webroster-adms-server/blob/main/app/Services/PopulateEmployeesService.php
     */
    public function run()
    {
        Log::info('PopulateEmployeesService', ['job' => self::class]);
        
        $employees = Agente::where('idempresa', $this->device->idempresa)
                            ->where('idoficina', $this->device->idoficina)->get();
        Log::info('Employees retrieved', ['employees' => $employees]);

        $lastCommand = $this->device->commands()->latest()->first();

        $CmdId = $lastCommand ? $lastCommand->id : 0;

        foreach ($employees as $employee) {
            
            // create a command to populate the employee
            $command = $this->device->commands()->create([
                'command' => $CmdId,
                'status' => 'pending',
                'device_id' => $this->device->id,
                'data' => $this->updateEmployee($employee, $CmdId)
            ]);   
            $CmdId++;
            Log::info('Command created', ['command' => $command]);
        }
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
