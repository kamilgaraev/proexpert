<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools;

use App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\UpdateMeasurementUnitAction;
use App\BusinessModules\Features\AIAssistant\Services\WriteAction;

class UpdateMeasurementUnitTool extends MeasurementUnitWriteTool
{
    public function __construct(private readonly UpdateMeasurementUnitAction $writeAction)
    {
    }

    protected function action(): WriteAction { return $this->writeAction; }
    protected function toolName(): string { return 'update_measurement_unit'; }
    protected function description(): string { return 'Изменяет единицу измерения в организации.'; }
    protected function schema(): array { return $this->unitSchema(true); }
}
