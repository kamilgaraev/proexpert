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
            if (!is_string($attribute)
                || (string) $event->getAttribute($attribute) !== (string) $value
            ) {
                throw new InvalidArgumentException('production_acceptance_event_immutable');
            }
        }
    }
}
