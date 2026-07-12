<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final readonly class QuantityFormulaItemData
{
    /**
     * @param  array<string, mixed>  $namedOperands
     * @param  array<int, string>  $evidenceIds
     * @param  array<int, string>  $assumptions
     * @param  array<int, string>  $contexts
     * @param  array<int, string>  $provenanceVersions
     */
    public function __construct(
        public string $identity,
        public string $amount,
        public array $namedOperands,
        public QuantitySource $source,
        public array $evidenceIds,
        public array $assumptions,
        public array $contexts,
        public array $provenanceVersions,
    ) {
        if ($namedOperands === []) {
            throw new \InvalidArgumentException('Formula item operands are required.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity, 'amount' => $this->amount,
            'named_operands' => $this->namedOperands, 'source' => $this->source->value,
            'evidence_ids' => $this->evidenceIds, 'assumptions' => $this->assumptions,
            'contexts' => $this->contexts, 'provenance_versions' => $this->provenanceVersions,
        ];
    }
}
