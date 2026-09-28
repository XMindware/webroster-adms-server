<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per HTTP attempt to deliver an attendance batch to a device's
 * webhook. Written by App\Services\WebhookDeliveryLogger.
 *
 * The "webhook" log file already carries a line per attempt, but a log file is
 * rotated away and cannot be filtered by device. This table is what the
 * delivery-history screen reads, so the question "did this terminal's receiver
 * actually get anything last night?" can be answered without shell access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();

            // Nullable, and SET NULL rather than CASCADE: the record of what was
            // sent has to outlive the webhook row it came from. Deleting a
            // misbehaving webhook must not erase the evidence of its behaviour.
            $table->foreignId('webhook_id')->nullable()->constrained('webhooks')->nullOnDelete();

            // Copied rather than joined. The job holds only primitives and the
            // device row may be renamed or removed long before anyone reads
            // this history.
            $table->string('device_sn', 64)->nullable();

            $table->string('url');
            $table->unsignedInteger('records')->default(0);

            // 1-based, and only meaningful off a real queue: on the
            // sync/after-response path there is no attempt counter at all.
            $table->unsignedTinyInteger('attempt')->default(1);

            // Null when the receiver was never reached - DNS failure, refused
            // connection, timeout - so there is no status code to report.
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('successful')->default(false);
            $table->text('error')->nullable();

            $table->timestamps();

            // The history screen is always "this webhook, newest first". The
            // foreign key already indexes webhook_id on its own, but not with
            // created_at after it.
            $table->index(['webhook_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
