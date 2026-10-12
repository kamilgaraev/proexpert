<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

interface ProjectSynthesisAllowance
{
    /** Null identifies the legacy path; zero excludes a model call from the approved plan. */
    public function partLimit(ProjectSynthesisInput $input): ?int;
}
