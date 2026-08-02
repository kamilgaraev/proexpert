<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Backfill;

final readonly class HistoricalInventoryMovementEvidence
{
    private function __construct(
        public int $sourceVersion,
        public string $unitDimension,
        public string $unitCode,
        public string $conversionVersion,
        public ?int $projectId,
        public ?string $currency,
        public ?string $currencySource,
        public ?string $openingBasis,
    ) {}

    public static function fromMetadata(array $metadata): ?self
    {
        $sourceVersion = $metadata['reporting_source_version'] ?? null;
        $unitDimension = $metadata['unit_dimension'] ?? null;
        $unitCode = $metadata['unit_code'] ?? null;
        $conversionVersion = $metadata['unit_conversion_version'] ?? null;
        $projectId = $metadata['reporting_inventory_project_id'] ?? null;
        if (! is_int($sourceVersion)
            || $sourceVersion < 1
            || ! self::filled($unitDimension)
            || ! self::filled($unitCode)
            || ! self::filled($conversionVersion)
            || ! array_key_exists('reporting_inventory_project_id', $metadata)
            || ($projectId !== null && (! is_int($projectId) || $projectId < 1))) {
            return null;
        }

        $currency = $metadata['currency'] ?? null;
        $currencySource = $metadata['currency_source'] ?? null;
        if (($currency !== null || $currencySource !== null)
            && (! self::filled($currency) || ! self::filled($currencySource))) {
            return null;
        }

        $openingBasis = $metadata['reporting_opening_basis'] ?? null;
        if ($openingBasis !== null
            && (! is_string($openingBasis)
                || ! in_array($openingBasis, ['verified_zero', 'opening_inventory', 'prior_verified_closing'], true))) {
            return null;
        }

        return new self(
            $sourceVersion,
            trim($unitDimension),
            trim($unitCode),
            trim($conversionVersion),
            $projectId,
            $currency === null ? null : trim($currency),
            $currencySource === null ? null : trim($currencySource),
            $openingBasis,
        );
    }

    public function recorderBasis(): array
    {
        $basis = [
            'reporting_source_version' => $this->sourceVersion,
            'unit_dimension' => $this->unitDimension,
            'unit_code' => $this->unitCode,
            'unit_conversion_version' => $this->conversionVersion,
            'reporting_inventory_project_id' => $this->projectId,
        ];
        if ($this->currency !== null) {
            $basis['currency'] = $this->currency;
            $basis['currency_source'] = $this->currencySource;
        }
        if ($this->openingBasis !== null) {
            $basis['reporting_opening_basis'] = $this->openingBasis;
        }

        return $basis;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
