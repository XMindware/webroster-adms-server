<?php

namespace App\Console\Commands;

use App\Models\EmpresaApiKey;
use Illuminate\Console\Command;

class CreateEmpresaApiKey extends Command
{
    protected $signature = 'empresa-keys:create {idempresa : Empresa the key grants access to} {--name= : Label, e.g. "webroster prod"}';
    protected $description = 'Issue an API key for an empresa to query its device status';

    public function handle(): int
    {
        [$key, $plaintext] = EmpresaApiKey::issue($this->argument('idempresa'), $this->option('name'));

        $this->info("API key #{$key->id} created for empresa {$key->idempresa}. Store it now; it cannot be shown again:");
        $this->line($plaintext);

        return self::SUCCESS;
    }
}
