<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools;

use App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\MassCreateMeasurementUnitsAction;
use App\BusinessModules\Features\AIAssistant\Services\WriteAction;

class MassCreateMeasurementUnitsTool extends MeasurementUnitWriteTool
{
    public function __construct(private readonly MassCreateMeasurementUnitsAction $writeAction)
    {
    }

    protected function action(): WriteAction { return $this->writeAction; }
    protected function toolName(): string { return 'mass_create_measurement_units'; }
    protected function description(): string { return 'Создаёт несколько единиц измерения в организации.'; }
    protected function schema(): array
    {
        $unit = $this->unitSchema();
        return ['type' => 'object', 'properties' => ['units' => ['type' => 'array', 'maxItems' => 20, 'items' => $unit]], 'required' => ['units']];
    }
}
