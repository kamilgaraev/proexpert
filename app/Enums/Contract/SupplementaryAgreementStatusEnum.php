<?php

namespace App\Enums\Contract;

enum SupplementaryAgreementStatusEnum: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case SUPERSEDED = 'superseded';
    case CANCELLED = 'cancelled';
}

