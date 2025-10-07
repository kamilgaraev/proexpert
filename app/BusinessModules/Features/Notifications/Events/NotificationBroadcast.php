<?php

namespace App\BusinessModules\Features\Notifications\Events;

use App\BusinessModules\Features\Notifications\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Notification $notification;
    public $notifiable;

    public function __construct(Notification $notification, $notifiable)
    {
        $this->notification = $notification;
        $this->notifiable = $notifiable;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->notifiable->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.new';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'notification_type' => $this->notification->notification_type,
            'priority' => $this->notification->priority,
            'data' => $this->notification->data,
            'created_at' => $this->notification->created_at->toIso8601String(),
            'read_at' => $this->notification->read_at?->toIso8601String(),
        ];
    }
}

