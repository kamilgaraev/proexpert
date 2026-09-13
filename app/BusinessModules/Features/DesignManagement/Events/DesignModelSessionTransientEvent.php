<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DesignModelSessionTransientEvent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        private readonly int $sessionId,
        private readonly string $type,
        private readonly array $payload,
        private readonly array $sender,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("design-model-session.{$this->sessionId}");
    }

    public function broadcastAs(): string
    {
        return 'design-model-session.transient';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'sender' => $this->sender,
        ];
    }
}
