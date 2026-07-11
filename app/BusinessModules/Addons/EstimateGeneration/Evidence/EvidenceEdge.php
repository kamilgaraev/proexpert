<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

final readonly class EvidenceEdge
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public int $parentId,
        public int $childId,
        public EvidenceRelation $relation,
    ) {}
}
