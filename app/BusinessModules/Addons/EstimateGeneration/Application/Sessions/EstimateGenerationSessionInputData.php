<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationConstructionType;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;

final readonly class EstimateGenerationSessionInputData
{
    private const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $parameters */
    private function __construct(
        public ?string $description,
        public ?string $buildingType,
        public EstimateGenerationMode $generationMode,
        public ?string $region,
        public ?EstimateGenerationConstructionType $constructionType,
        public ?float $area,
        public ?int $floors,
        public ?float $height,
        public ?int $periodId,
        public ?int $regionalPriceVersionId,
        public ?int $regionId,
        public ?int $priceZoneId,
        public ?string $normativeDatasetVersion,
        public bool $normativeRerankRequested,
        public array $parameters,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromValidated(array $input): self
    {
        return new self(
            description: self::nullableString($input['description'] ?? null),
            buildingType: self::nullableString($input['building_type'] ?? null),
            generationMode: EstimateGenerationMode::fromInput($input['generation_mode'] ?? null),
            region: self::nullableString($input['region'] ?? null),
            constructionType: is_string($input['construction_type'] ?? null)
                ? EstimateGenerationConstructionType::from($input['construction_type'])
                : null,
            area: self::nullableFloat($input['area'] ?? null),
            floors: self::nullableInt($input['floors'] ?? null),
            height: self::nullableFloat($input['height'] ?? null),
            periodId: self::nullableInt($input['period_id'] ?? null),
            regionalPriceVersionId: self::nullableInt($input['estimate_regional_price_version_id'] ?? null),
            regionId: self::nullableInt($input['region_id'] ?? null),
            priceZoneId: self::nullableInt($input['price_zone_id'] ?? null),
            normativeDatasetVersion: self::nullableString($input['normative_dataset_version'] ?? null),
            normativeRerankRequested: ($input['normative_rerank_requested'] ?? false) === true,
            parameters: is_array($input['parameters'] ?? null) ? $input['parameters'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'description' => $this->description,
            'building_type' => $this->buildingType,
            'generation_mode' => $this->generationMode->value,
            'region' => $this->region,
            'construction_type' => $this->constructionType?->value,
            'area' => $this->area,
            'floors' => $this->floors,
            'height' => $this->height,
            'period_id' => $this->periodId,
            'estimate_regional_price_version_id' => $this->regionalPriceVersionId,
            'region_id' => $this->regionId,
            'price_zone_id' => $this->priceZoneId,
            'normative_dataset_version' => $this->normativeDatasetVersion,
            'normative_rerank_requested' => $this->normativeRerankRequested,
            'parameters' => $this->parameters,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
