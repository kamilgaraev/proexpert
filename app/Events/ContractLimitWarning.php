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

class ContractLimitWarning implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organization.' . $this->contract->organization_id)
        ];
    }

    public function broadcastAs(): string
    {
        return 'contract.limit.warning';
    }

    public function broadcastWith(): array
    {
        return [
            'contract_id' => $this->contract->id,
            'contract_number' => $this->contract->number,
            'project_name' => $this->contract->project?->name,
            'completion_percentage' => $this->contract->completion_percentage,
            'completed_works_amount' => $this->contract->completed_works_amount,
            'total_amount' => $this->contract->total_amount,
            'remaining_amount' => $this->contract->remaining_amount,
            'level' => $this->getWarningLevel(),
            'message' => $this->getWarningMessage(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function getWarningLevel(): string
    {
        $percentage = $this->contract->completion_percentage;
        
        if ($percentage >= 98) {
            return 'critical';
        } elseif ($percentage >= 95) {
            return 'high';
        } elseif ($percentage >= 90) {
            return 'medium';
        }
        
        return 'low';
    }

    private function getWarningMessage(): string
    {
        $percentage = $this->contract->completion_percentage;
        
        if ($percentage >= 100) {
            return "Контракт {$this->contract->number} выполнен на 100%. Требуется завершение.";
        } elseif ($percentage >= 98) {
            return "КРИТИЧНО: Контракт {$this->contract->number} почти исчерпан ({$percentage}%)";
        } elseif ($percentage >= 95) {
            return "ВНИМАНИЕ: Контракт {$this->contract->number} близок к лимиту ({$percentage}%)";
        } else {
            return "Контракт {$this->contract->number} приближается к лимиту ({$percentage}%)";
        }
    }
} 