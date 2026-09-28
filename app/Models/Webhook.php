<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Webhook extends Model
{
    use HasFactory;

    protected $table = 'webhooks';

    /**
     * Every webhook gets a signing secret, whatever created it - the UI, a
     * seeder or a factory. A webhook with no secret is delivered unsigned, and
     * an unsigned delivery is one the receiver cannot tell apart from a forged
     * one.
     */
    protected static function booted(): void
    {
        static::creating(function (self $webhook) {
            if (empty($webhook->secret)) {
                $webhook->secret = Str::random(40);
            }
        });
    }

    protected $fillable = [
        'device_id',
        'url',
        'secret',
    ];

    protected $hidden = [
        'secret',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /**
     * Every attempt made through this webhook, the failed ones included.
     *
     * Rows outlive the webhook itself (webhook_id becomes null on delete), so
     * this relation only ever covers the deliveries whose configuration still
     * exists.
     */
    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_id');
    }
}
