<?php

namespace App\Enums\Contract;

enum ContractStateEventTypeEnum: string
{
    case CREATED = 'created';
    case AMENDED = 'amended';
    case SUPERSEDED = 'superseded';
    case CANCELLED = 'cancelled';
}

