<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;

interface QualityDefectFlowOwnerEventSink
{
    public function record(
        QualityDefect $defect,
        QualityDefectStatusHistory $history,
        QualityDefectFlowEventKind $eventKind,
        ?QualityDefectFlowTerminalReason $terminalReason = null,
    ): string;
}
