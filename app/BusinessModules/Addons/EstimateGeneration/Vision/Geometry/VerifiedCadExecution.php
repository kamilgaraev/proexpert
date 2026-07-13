<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry;

final readonly class VerifiedCadExecution
{
    /** @param list<string> $command @param array<string, string> $artifactHashes */
    public function __construct(public array $command, public array $artifactHashes) {}
}
