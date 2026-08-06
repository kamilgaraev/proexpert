<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use InvalidArgumentException;

final readonly class ProductionAcceptanceEventIdentity
{
    public function assertMatches(ProductionAcceptanceEvent $event, array $expected): void
    {
        foreach ($expected as $attribute => $value) {
            $actual = $event->getAttribute($attribute);
            if (in_array($attribute, [
                'accepted_quantity_delta',
                'planned_quantity',
                'reported_quantity',
            ], true)) {
                $actual = AcceptedProductionQuantity::normalize(
                    (string) $actual,
                    'production_acceptance_event_immutable',
                );
                $value = AcceptedProductionQuantity::normalize(
                    (string) $value,
                    'production_acceptance_event_immutable',
                );
            }
            if (!is_string($attribute)
                || (string) $actual !== (string) $value
            ) {
                throw new InvalidArgumentException('production_acceptance_event_immutable');
            }
        }
    }
}
