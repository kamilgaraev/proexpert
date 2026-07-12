<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final readonly class QuantityData
{
    /**
     * @param  array<string, mixed>  $formulaInputs
     * @param  array<int, string>  $evidenceIds
     * @param  array<int, string>  $assumptions
     * @param  array<int, string>  $reviewBlockers
     */
    public function __construct(
        public string $key,
        public string $unit,
        public string $amount,
        public string $formulaKey,
        public string $formulaVersion,
        public array $formulaInputs,
        public QuantitySource $source,
        public array $evidenceIds,
        public string $modelVersion,
        public array $assumptions = [],
        public array $reviewBlockers = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'unit' => $this->unit, 'amount' => $this->amount,
            'formula_key' => $this->formulaKey, 'formula_version' => $this->formulaVersion,
            'formula_inputs' => $this->formulaInputs, 'source' => $this->source->value,
            'evidence_ids' => $this->evidenceIds, 'model_version' => $this->modelVersion,
            'assumptions' => $this->assumptions, 'review_blockers' => $this->reviewBlockers,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'], unit: (string) $data['unit'], amount: (string) $data['amount'],
            formulaKey: (string) $data['formula_key'], formulaVersion: (string) $data['formula_version'],
            formulaInputs: (array) $data['formula_inputs'],
            source: QuantitySource::from((string) $data['source']),
            evidenceIds: array_values(array_map('strval', (array) $data['evidence_ids'])),
            modelVersion: (string) $data['model_version'],
            assumptions: array_values(array_map('strval', (array) ($data['assumptions'] ?? []))),
            reviewBlockers: array_values(array_map('strval', (array) ($data['review_blockers'] ?? []))),
        );
    }
}
