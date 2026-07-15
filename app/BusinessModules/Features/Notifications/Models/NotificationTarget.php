<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Models;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationTarget extends Model
{
    use HasUuids;

    protected $fillable = [
        'notification_id',
        'interface',
        'sequence',
        'read_at',
        'dismissed_at',
        'websocket_status',
        'websocket_delivered_at',
        'websocket_last_error',
    ];

    protected $casts = [
        'interface' => NotificationInterface::class,
        'sequence' => 'integer',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'websocket_delivered_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function markAsRead(): void
    {
        $this->forceFill(['read_at' => now()])->save();
    }

    public function markAsUnread(): void
    {
        $this->forceFill(['read_at' => null])->save();
    }

    public function dismiss(): void
    {
        $this->forceFill(['dismissed_at' => now()])->save();
    }

    public function markWebSocketSent(): void
    {
        $this->forceFill([
            'websocket_status' => 'sent',
            'websocket_delivered_at' => now(),
            'websocket_last_error' => null,
        ])->save();
    }

    public function markWebSocketFailed(string $error): void
    {
        $this->forceFill([
            'websocket_status' => 'failed',
            'websocket_delivered_at' => null,
            'websocket_last_error' => mb_substr($error, 0, 2000),
        ])->save();
    }
}
