<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-empresa credentials for the read-only device status API WebRoster polls.
 *
 * Guarded so it can be re-run: production here has already been found with a
 * migration half-applied (see 2026_09_23_100000_add_attendances_lookup_indexes),
 * and a fatal "table already exists" mid-deploy is worse than a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('empresa_api_keys')) {
            return;
        }

        Schema::create('empresa_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempresa')->index();
            $table->string('name')->nullable();
            // sha256 of the plaintext key; the plaintext is shown once, by
            // `empresa-keys:create`, and is never stored.
            $table->string('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            // Stamped rather than deleted, so the row still records which key
            // was live when.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_api_keys');
    }
};
