<?php

namespace App\Events;

use App\Models\Contract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractStatusChanged
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
}
