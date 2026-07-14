<?php

namespace App\Events;

use App\Models\Contract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractLimitWarning
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function getWarningLevel(): string
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

    public function getWarningMessage(): string
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
