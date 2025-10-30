<?php

namespace App\Enums\Contract;

enum ContractStateEventTypeEnum: string
{
    case CREATED = 'created';
    case AMENDED = 'amended';
    case SUPERSEDED = 'superseded';
    case CANCELLED = 'cancelled';
    case SUPPLEMENTARY_AGREEMENT_CREATED = 'supplementary_agreement_created';
    case PAYMENT_CREATED = 'payment_created';
}

