<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

/** The role claim and physical request must describe exactly the same candidate set. */
final class ProjectSynthesisAttemptIdentity
{
    public static function fingerprint(ProjectSynthesisInput $input, array $links, array $questions): string
    {
        return hash('sha256', CanonicalPipelineJson::encode([
            'contract' => 'project-synthesis-candidates:v1', 'input_fingerprint' => $input->fingerprint(),
            'links' => $links, 'questions' => $questions,
        ]));
    }
}
