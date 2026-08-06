<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Enums\CurrencyCode;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ProductionAcceptanceRecognitionGrain
{
    public function key(ProductionAcceptanceEvent $event, DateTimeZone $timezone): string
    {
        if ((int) $event->project_id < 1
            || (int) $event->performance_act_id < 1
            || (int) $event->source_line_id < 1
            || (int) $event->work_id < 1
            || trim((string) $event->source_line_type) === ''
            || trim((string) $event->unit_dimension) === ''
            || trim((string) $event->unit_code) === ''
            || CurrencyCode::tryFrom((string) $event->currency) === null
            || $event->recognized_at === null
        ) {
            throw new InvalidArgumentException('production_acceptance_recognition_grain_invalid');
        }

        return implode(':', [
            (int) $event->project_id,
            $this->day($event, $timezone),
            (string) $event->unit_dimension,
            (string) $event->unit_code,
            (string) $event->currency,
            (int) $event->performance_act_id,
            (string) $event->source_line_type,
            (int) $event->source_line_id,
            (int) $event->work_id,
        ]);
    }

    public function day(ProductionAcceptanceEvent $event, DateTimeZone $timezone): string
    {
        if ($event->recognized_at === null) {
            throw new InvalidArgumentException('production_acceptance_recognition_grain_invalid');
        }

        return $event->recognized_at->setTimezone($timezone)->format('Y-m-d');
    }
}
