<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class ProjectCompletenessResult
{
    public function __construct(
        public string $ruleCatalogVersion,
        public string $ruleCatalogHash,
        public array $findings,
        public array $limitations,
    ) {}

    public function finding(string $ruleId): ?CompletenessFinding
    {
        foreach ($this->findings as $finding) {
            if ($finding->ruleId === $ruleId) {
                return $finding;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'rule_catalog_version' => $this->ruleCatalogVersion,
            'rule_catalog_hash' => $this->ruleCatalogHash,
            'findings' => array_map(static fn (CompletenessFinding $finding): array => $finding->toArray(), $this->findings),
            'limitations' => $this->limitations,
        ];
    }
}
