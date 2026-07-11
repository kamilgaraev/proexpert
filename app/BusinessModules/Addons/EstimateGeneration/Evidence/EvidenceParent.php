<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

final readonly class EvidenceParent
{
    public function __construct(public int $id, public EvidenceRelation $relation) {}
}
