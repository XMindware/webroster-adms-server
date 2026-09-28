<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One HTTP attempt to deliver a batch to a device's webhook.
 *
 * Read-only from the application's point of view: rows are written by
 * App\Services\WebhookDeliveryLogger and only ever listed, never edited. See
 * that class for why a failure to write one must never surface.
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'webhook_id',
        'device_sn',
        'url',
        'records',
        'attempt',
        'status',
        'duration_ms',
        'successful',
        'error',
    ];

    /**
     * Cast explicitly because the history screen renders these directly and a
     * MySQL driver hands back strings for every numeric column.
     */
    protected $casts = [
        'records' => 'integer',
        'attempt' => 'integer',
        'status' => 'integer',
        'duration_ms' => 'integer',
        'successful' => 'boolean',
    ];

    /**
     * The webhook this was sent through, if it still exists. Null once the
     * webhook is deleted - the delivery itself is kept.
     */
    public function webhook()
    {
        return $this->belongsTo(Webhook::class, 'webhook_id');
    }
}
