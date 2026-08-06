<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\ApprovedAcceptanceRate;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use InvalidArgumentException;

final readonly class ProductionAcceptanceReversalSource
{
    public function fromAccepted(ProductionAcceptanceEvent $event): array
    {
        if ($event->event_type !== 'accepted'
            || preg_match('/^\+?\d+(?:\.\d{1,4})?$/D', (string) $event->accepted_quantity_delta) !== 1
            || $event->approved_rate_minor === null
            || $event->currency === null
            || $event->currency_source === null
            || (int) $event->work_id < 1
        ) {
            throw new InvalidArgumentException('production_acceptance_reversal_source_invalid');
        }

        return [
            'accepted_quantity_delta' => '-'.ltrim((string) $event->accepted_quantity_delta, '+'),
            'approved_rate' => new ApprovedAcceptanceRate(
                (int) $event->approved_rate_minor,
                (string) $event->currency,
                (string) $event->currency_source,
            ),
            'contractor_id' => $event->contractor_id === null ? null : (int) $event->contractor_id,
            'conversion_version' => (string) $event->conversion_version,
            'planned_quantity' => (string) $event->planned_quantity,
            'reported_quantity' => (string) $event->reported_quantity,
            'task_id' => $event->task_id === null ? null : (int) $event->task_id,
            'unit_code' => (string) $event->unit_code,
            'unit_dimension' => (string) $event->unit_dimension,
            'wbs_code' => $event->wbs_code,
            'work_id' => (int) $event->work_id,
            'zone' => $event->zone,
        ];
    }
}
