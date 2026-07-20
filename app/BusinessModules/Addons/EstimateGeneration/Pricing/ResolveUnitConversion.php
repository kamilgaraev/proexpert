<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use Closure;
use Illuminate\Support\Facades\DB;

final readonly class ResolveUnitConversion
{
    public function __construct(private ?Closure $lookup = null) {}

    public function handle(string $fromUnit, string $toUnit, int $version): ?UnitConversionData
    {
        if ($fromUnit === $toUnit) {
            return null;
        }
        if ($fromUnit === '' || $toUnit === '' || $version < 1) {
            throw MissingRegionalPrice::forResource(0, 'unit_conversion_identity_invalid');
        }

        $rows = $this->lookup !== null
            ? ($this->lookup)($fromUnit, $toUnit, $version)
            : DB::table('estimate_generation_unit_conversions')
                ->where('from_unit', $fromUnit)
                ->where('to_unit', $toUnit)
                ->where('version', $version)
                ->where('is_active', true)
                ->limit(2)
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all();
        if (! is_array($rows) || count($rows) !== 1 || ! is_array($rows[0])) {
            throw MissingRegionalPrice::forResource(0, 'unit_conversion_missing');
        }
        $row = $rows[0];
        if (($row['is_active'] ?? false) !== true
            || (string) ($row['from_unit'] ?? '') !== $fromUnit
            || (string) ($row['to_unit'] ?? '') !== $toUnit
            || (int) ($row['version'] ?? 0) !== $version
            || (int) ($row['id'] ?? 0) < 1
            || preg_match('/^[0-9a-f]{64}$/D', (string) ($row['fingerprint'] ?? '')) !== 1) {
            throw MissingRegionalPrice::forResource(0, 'unit_conversion_payload_invalid');
        }

        return new UnitConversionData(
            id: (int) $row['id'],
            fromUnit: (string) $row['from_unit'],
            toUnit: (string) $row['to_unit'],
            factor: (string) $row['factor'],
            version: (int) $row['version'],
            fingerprint: (string) $row['fingerprint'],
        );
    }
}
