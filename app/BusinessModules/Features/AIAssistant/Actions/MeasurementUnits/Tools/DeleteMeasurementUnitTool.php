<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\Tools;

use App\BusinessModules\Features\AIAssistant\Actions\MeasurementUnits\DeleteMeasurementUnitAction;
use App\BusinessModules\Features\AIAssistant\Services\WriteAction;

class DeleteMeasurementUnitTool extends MeasurementUnitWriteTool
{
    public function __construct(private readonly DeleteMeasurementUnitAction $writeAction)
    {
    }

    protected function action(): WriteAction { return $this->writeAction; }
    protected function toolName(): string { return 'delete_measurement_unit'; }
    protected function description(): string { return 'Удаляет единицу измерения в организации.'; }
    protected function schema(): array { return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['id']]; }
}
