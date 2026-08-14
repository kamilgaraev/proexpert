<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

interface ProjectSynthesisRunner
{
    /** @param list<array<string, mixed>> $candidateLinks @param list<array<string, mixed>> $candidateQuestions */
    public function run(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
    ): ProjectSynthesisSelection;
}
