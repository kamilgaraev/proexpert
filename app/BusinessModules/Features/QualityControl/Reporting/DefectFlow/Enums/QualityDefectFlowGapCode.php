<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums;

enum QualityDefectFlowGapCode: string
{
    case SOURCE_CONTRACT_MISSING = 'source_contract_missing';
}
