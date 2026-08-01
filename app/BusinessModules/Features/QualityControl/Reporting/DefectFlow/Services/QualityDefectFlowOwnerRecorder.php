<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowOwnerEventSink;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;

final readonly class QualityDefectFlowOwnerRecorder implements QualityDefectFlowOwnerEventSink
{
    public function __construct(
        private QualityDefectFlowOwnerEventFactory $factory,
        private QualityDefectFlowEventRecorder $recorder,
    ) {}

    public function record(
        QualityDefect $defect,
        QualityDefectStatusHistory $history,
        QualityDefectFlowEventKind $eventKind,
        ?QualityDefectFlowTerminalReason $terminalReason = null,
    ): string {
        return $this->recorder->record($this->factory->make(
            $defect,
            $history,
            $eventKind,
            $terminalReason,
        ));
    }
}
