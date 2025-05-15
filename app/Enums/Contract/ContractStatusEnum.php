<?php

namespace App\Enums\Contract;

enum ContractStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ON_HOLD = 'on_hold';
    case TERMINATED = 'terminated';
} 