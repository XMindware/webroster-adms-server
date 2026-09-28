<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('webhooks', 'secret')) {
            Schema::table('webhooks', function (Blueprint $table) {
                $table->string('secret', 64)->nullable()->after('url');
            });
        }

        // Backfill existing rows so deliveries are signed from the first POST
        // rather than only for webhooks created from now on.
        foreach (DB::table('webhooks')->whereNull('secret')->get() as $webhook) {
            DB::table('webhooks')
                ->where('id', $webhook->id)
                ->update(['secret' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('webhooks', 'secret')) {
            Schema::table('webhooks', function (Blueprint $table) {
                $table->dropColumn('secret');
            });
        }
    }
};
