<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AssistantRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        private readonly string $requestId,
        private readonly int $userId,
        private readonly int $organizationId,
        private readonly string $surface,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}.{$this->surface}.org.{$this->organizationId}")];
    }

    public function broadcastAs(): string
    {
        return 'assistant.request.changed';
    }

    public function broadcastWith(): array
    {
        return ['request_id' => $this->requestId];
    }
}
