<?php

namespace App\Events;

use App\Models\Contract;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Contract $contract;
    public string $oldStatus;
    public string $newStatus;

    public function __construct(Contract $contract, string $oldStatus, string $newStatus)
    {
        $this->contract = $contract;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organization.' . $this->contract->organization_id)
        ];
    }

    public function broadcastAs(): string
    {
        return 'contract.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id' => $this->contract->id,
            'contract_number' => $this->contract->number,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'completion_percentage' => $this->contract->completion_percentage,
            'is_nearing_limit' => $this->contract->isNearingLimit(),
            'message' => "Статус контракта {$this->contract->number} изменен с {$this->oldStatus} на {$this->newStatus}",
            'timestamp' => now()->toIso8601String(),
        ];
    }
} 