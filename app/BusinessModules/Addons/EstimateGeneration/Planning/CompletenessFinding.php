<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class CompletenessFinding
{
    public function __construct(
        public string $ruleId,
        public string $ruleVersion,
        public string $ruleHash,
        public string $stableKey,
        public int $version,
        public string $classification,
        public string $status,
        public string $severity,
        public string $impact,
        public float $confidence,
        public array $evidenceFactIds,
        public array $relatedEntityIds,
        public array $relatedFactTypes,
        public array $applicability,
        public array $exclusionPolicy,
        public ?array $exclusionDecision,
        public ?TechnologyWorkPackage $workPackage,
    ) {}

    public function toArray(): array
    {
        return [...get_object_vars($this), 'workPackage' => $this->workPackage?->toArray()];
    }

    public function exclusionValue(
        array $projection,
        string $decisionId,
        string $actor,
        string $reason,
    ): array {
        if (($this->exclusionPolicy['allowed'] ?? false) !== true) {
            throw new \InvalidArgumentException('Completeness finding cannot be excluded.');
        }
        foreach (['source_version', 'input_fingerprint', 'catalog_version', 'catalog_hash'] as $key) {
            if (! is_string($projection[$key] ?? null) || $projection[$key] === '') {
                throw new \InvalidArgumentException('Completeness exclusion projection is invalid.');
            }
        }

        return [
            'rule_id' => $this->ruleId,
            'rule_version' => $this->ruleVersion,
            'rule_hash' => $this->ruleHash,
            'finding_key' => $this->stableKey,
            'finding_version' => $this->version,
            'policy_id' => $this->exclusionPolicy['id'],
            'policy_version' => $this->exclusionPolicy['version'],
            'source_version' => $projection['source_version'],
            'input_fingerprint' => $projection['input_fingerprint'],
            'catalog_version' => $projection['catalog_version'],
            'catalog_hash' => $projection['catalog_hash'],
            'decision_id' => $decisionId,
            'actor' => $actor,
            'reason' => $reason,
        ];
    }
}
