<?php

namespace App\Enums\Contract;

use function trans_message;

enum ContractStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ON_HOLD = 'on_hold';
    case TERMINATED = 'terminated';

    public function label(): string
    {
        return trans_message("contract.statuses.{$this->value}");
    }
}
