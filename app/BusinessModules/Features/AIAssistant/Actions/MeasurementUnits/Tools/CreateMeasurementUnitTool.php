<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools;

use App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\CreateMeasurementUnitAction;
use App\BusinessModules\Features\AIAssistant\Services\WriteAction;

class CreateMeasurementUnitTool extends MeasurementUnitWriteTool
{
    public function __construct(private readonly CreateMeasurementUnitAction $writeAction)
    {
    }

    protected function action(): WriteAction { return $this->writeAction; }
    protected function toolName(): string { return 'create_measurement_unit'; }
    protected function description(): string { return 'Создаёт единицу измерения в организации.'; }
    protected function schema(): array { return $this->unitSchema(); }
}
