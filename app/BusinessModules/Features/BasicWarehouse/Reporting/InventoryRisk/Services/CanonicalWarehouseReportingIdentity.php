<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use DomainException;

final readonly class CanonicalWarehouseReportingIdentity
{
    private const KEYS = [
        'reporting_source_version',
        'unit_dimension',
        'unit_code',
        'unit_conversion_version',
        'reporting_inventory_project_id',
        'currency',
        'currency_source',
        'reporting_opening_basis',
    ];

    public function merge(array $canonical, array $metadata): array
    {
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $metadata)
                && $metadata[$key] !== $canonical[$key]) {
                throw new DomainException("Warehouse reporting identity {$key} conflicts with its source owner.");
            }
        }

        return array_merge($metadata, $canonical);
    }
}
