<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum WaveOneCandidateBindingStatus: string
{
    case IMPLEMENTED = 'implemented';
    case BLOCKED_BY_SOURCE_READINESS = 'blocked_by_source_readiness';
    case BLOCKED_BY_SOURCE_CONTRACT = 'blocked_by_source_contract';
}
