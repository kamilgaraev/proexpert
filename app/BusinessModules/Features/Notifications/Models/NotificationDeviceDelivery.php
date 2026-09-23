<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationDeviceDelivery extends Model
{
    protected $fillable = [
        'notification_id',
        'device_id',
        'installation_id',
        'token_hash',
        'status',
        'message_id',
        'attempts',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NotificationDevice::class, 'device_id');
    }
}
