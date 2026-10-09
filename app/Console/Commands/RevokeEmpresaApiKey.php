<?php

namespace App\Console\Commands;

use App\Models\EmpresaApiKey;
use Illuminate\Console\Command;

class RevokeEmpresaApiKey extends Command
{
    protected $signature = 'empresa-keys:revoke {id : Key id}';

    protected $description = 'Revoke an empresa API key';

    public function handle(): int
    {
        $key = EmpresaApiKey::find($this->argument('id'));

        if (!$key) {
            $this->error('Key not found.');

            return self::FAILURE;
        }

        // Stamped, not deleted: the row keeps the history of which key was in
        // use when, which is what you need after a leak has been reported.
        $key->update(['revoked_at' => now()]);
        $this->info("Key #{$key->id} (empresa {$key->idempresa}) revoked.");

        return self::SUCCESS;
    }
}
