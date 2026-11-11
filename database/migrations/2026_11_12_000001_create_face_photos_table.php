<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Face comparison photos (BIOPHOTO) pulled from the terminals.
 *
 * A visible-light terminal keeps a face enrolment as a JPEG "comparison photo"
 * from which it extracts its own template. The photo is the portable form — a
 * template only works on the algorithm version that produced it — so it is
 * stored on its own, apart from `face_templates`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('face_photos')) {
            return;
        }

        Schema::create('face_photos', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->string('sn')->index();

            // PIN as reported by the terminal == agentes.idagente
            $table->string('pin')->index();

            // Biometric type the photo enrols (9 = visible-light face).
            $table->unsignedTinyInteger('bio_type')->default(9);

            $table->string('file_name')->nullable();
            $table->unsignedInteger('size')->nullable();

            // Base64 JPEG exactly as sent by the device.
            $table->longText('content');
            $table->string('content_hash', 40)->index();

            $table->string('idempresa')->nullable()->index();
            $table->string('idoficina')->nullable()->index();

            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            // One photo per biometric type per employee per terminal.
            $table->unique(['sn', 'pin', 'bio_type'], 'face_photos_sn_pin_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_photos');
    }
};
