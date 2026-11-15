<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real storage for biometric templates pulled from the terminals.
 *
 * The legacy `fingerprints` table stores `fullrecord` as a VARCHAR(255), which
 * silently truncates (or rejects, under strict mode) every template it is given
 * — a ZKTeco fingerprint template is ~1.4-2.5 KB of base64. This table is the
 * one the pull/push pipeline actually uses; `fingerprints` is left alone as the
 * historical raw capture log.
 *
 * @author XMindware
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_templates', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->string('sn')->index();

            // PIN as reported by the terminal == agentes.idagente
            $table->string('pin')->index();
            // Finger index 0..9 (FID / Index depending on firmware)
            $table->unsignedTinyInteger('fid')->default(0);

            $table->unsignedInteger('size')->nullable();
            $table->unsignedTinyInteger('valid')->default(1);
            $table->unsignedTinyInteger('duress')->default(0);

            // Template payload as sent by the device (base64). longText so a
            // multi-KB template is never truncated.
            $table->longText('template');
            // sha1 of the template, so re-pulls that return identical data are
            // cheap to detect without comparing multi-KB blobs.
            $table->string('template_hash', 40)->index();

            // Denormalised so templates can be scoped without joining devices.
            $table->string('idempresa')->nullable()->index();
            $table->string('idoficina')->nullable()->index();

            // 'push'  = device volunteered it (enrolment, TransFlag)
            // 'query' = we asked for it (DATA QUERY FINGERTMP / CHECK)
            $table->string('source', 16)->default('push');
            $table->string('format', 16)->nullable();
            $table->string('version', 16)->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            // One row per finger per employee per terminal.
            $table->unique(['sn', 'pin', 'fid'], 'fingerprint_templates_sn_pin_fid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_templates');
    }
};
