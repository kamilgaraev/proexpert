<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs;

final readonly class ObjectProfileData
{
    /**
     * @param array<string, mixed> $dimensions
     * @param array<int, string> $finishLevels
     * @param array<int, string> $engineeringSystems
     * @param array<int, string> $assumptions
     * @param array<int, string> $missingInputs
     * @param array<string, mixed> $planningSignals
     */
    public function __construct(
        public string $objectType,
        public ?float $area,
        public ?int $floors,
        public ?int $rooms,
        public ?string $regionCode,
        public ?int $regionalPriceVersionId,
        public ?string $quarterKey,
        public array $dimensions,
        public array $finishLevels,
        public array $engineeringSystems,
        public array $assumptions,
        public array $missingInputs,
        public float $confidence,
        public array $planningSignals = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            objectType: (string) ($payload['object_type'] ?? $payload['objectType'] ?? 'custom'),
            area: isset($payload['area']) ? (float) $payload['area'] : null,
            floors: isset($payload['floors']) ? (int) $payload['floors'] : null,
            rooms: isset($payload['rooms']) ? (int) $payload['rooms'] : null,
            regionCode: isset($payload['region_code']) ? (string) $payload['region_code'] : null,
            regionalPriceVersionId: isset($payload['regional_price_version_id']) ? (int) $payload['regional_price_version_id'] : null,
            quarterKey: isset($payload['quarter_key']) ? (string) $payload['quarter_key'] : null,
            dimensions: is_array($payload['dimensions'] ?? null) ? $payload['dimensions'] : [],
            finishLevels: array_values(array_map('strval', is_array($payload['finish_levels'] ?? null) ? $payload['finish_levels'] : [])),
            engineeringSystems: array_values(array_map('strval', is_array($payload['engineering_systems'] ?? null) ? $payload['engineering_systems'] : [])),
            assumptions: array_values(array_map('strval', is_array($payload['assumptions'] ?? null) ? $payload['assumptions'] : [])),
            missingInputs: array_values(array_map('strval', is_array($payload['missing_inputs'] ?? null) ? $payload['missing_inputs'] : [])),
            confidence: isset($payload['confidence']) ? (float) $payload['confidence'] : 0.5,
            planningSignals: is_array($payload['planning_signals'] ?? $payload['planningSignals'] ?? null)
                ? ($payload['planning_signals'] ?? $payload['planningSignals'])
                : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'object_type' => $this->objectType,
            'area' => $this->area,
            'floors' => $this->floors,
            'rooms' => $this->rooms,
            'region_code' => $this->regionCode,
            'regional_price_version_id' => $this->regionalPriceVersionId,
            'quarter_key' => $this->quarterKey,
            'dimensions' => $this->dimensions,
            'finish_levels' => $this->finishLevels,
            'engineering_systems' => $this->engineeringSystems,
            'assumptions' => $this->assumptions,
            'missing_inputs' => $this->missingInputs,
            'confidence' => $this->confidence,
            'planning_signals' => $this->planningSignals,
        ];
    }
}
