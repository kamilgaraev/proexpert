<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

interface ProjectSynthesisModel
{
    /**
     * @param  list<array<string, mixed>>  $candidateLinks
     * @param  list<array<string, mixed>>  $candidateQuestions
     * @param  callable(string):void  $onPhysicalAttemptReserved
     * @return array<string, mixed>
     */
    public function synthesize(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
        callable $onPhysicalAttemptReserved,
    ): array;
}
