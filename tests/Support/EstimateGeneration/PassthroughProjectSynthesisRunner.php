<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisRunner;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis\ProjectSynthesisSelection;

final readonly class PassthroughProjectSynthesisRunner implements ProjectSynthesisRunner
{
    public function run(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
    ): ProjectSynthesisSelection {
        return new ProjectSynthesisSelection(
            array_values(array_filter(array_map(
                static fn (array $link): mixed => $link['id'] ?? null,
                $candidateLinks,
            ), 'is_string')),
            array_values(array_filter(array_map(
                static fn (array $question): mixed => $question['conflict_id'] ?? null,
                $candidateQuestions,
            ), 'is_string')),
        );
    }
}
