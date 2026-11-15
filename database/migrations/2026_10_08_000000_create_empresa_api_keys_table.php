<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresa_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempresa')->index();
            $table->string('name')->nullable();
            // sha256 of the plaintext key; the plaintext is shown once at creation
            $table->string('key_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresa_api_keys');
    }
};
