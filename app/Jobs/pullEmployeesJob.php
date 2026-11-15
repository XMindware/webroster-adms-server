<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\SyncStationEmployeesService;
use Illuminate\Support\Facades\Log;

class pullEmployeesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $data;

    /**
     * Create a new job instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(SyncStationEmployeesService $service)
    {
        Log::info('Job started', ['job' => self::class]);

        $result = $service->syncOffice($this->data);

        Log::info('Job completed', ['job' => self::class, 'result' => $result]);

    }
}
