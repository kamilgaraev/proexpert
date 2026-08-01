<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowEvent;

interface QualityDefectFlowStore
{
    public function append(QualityDefectFlowEvent $event): string;
}
