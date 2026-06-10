<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class EpmDataMartSnapshotPayload
{
    public function __construct(
        public string $status,
        public string $formulaVersion,
        public string $sourceHash,
        public string $generatedAt,
        public array $payload,
        public array $freshness,
        public array $sourceRefs,
        public array $aggregates,
    ) {
    }

    public function publicFreshness(): array
    {
        return [
            'status' => $this->status,
            'formula_version' => $this->formulaVersion,
            'source_hash' => $this->sourceHash,
            'generated_at' => $this->generatedAt,
            'source_refs' => $this->sourceRefs,
        ];
    }
}
