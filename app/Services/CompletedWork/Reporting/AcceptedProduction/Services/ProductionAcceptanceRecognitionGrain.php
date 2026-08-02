<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use InvalidArgumentException;

final readonly class ProductionAcceptanceRecognitionGrain
{
    public function key(ProductionAcceptanceEvent $event): string
    {
        if ((int) $event->project_id < 1
            || (int) $event->performance_act_id < 1
            || (int) $event->source_line_id < 1
            || trim((string) $event->source_line_type) === ''
            || trim((string) $event->unit_dimension) === ''
            || trim((string) $event->unit_code) === ''
            || $event->recognized_at === null
        ) {
            throw new InvalidArgumentException('production_acceptance_recognition_grain_invalid');
        }

        return implode(':', [
            (int) $event->project_id,
            $event->recognized_at->format('Y-m-d'),
            (string) $event->unit_dimension,
            (string) $event->unit_code,
            (int) $event->performance_act_id,
            (string) $event->source_line_type,
            (int) $event->source_line_id,
        ]);
    }
}
