<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts;

interface QualityDefectFlowTransactionBoundary
{
    public function isActive(): bool;
}
