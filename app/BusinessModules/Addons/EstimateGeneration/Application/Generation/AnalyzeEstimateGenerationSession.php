<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionActionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final class AnalyzeEstimateGenerationSession
{
    public function __construct(private RequestEstimateGeneration $generation) {}

    public function handle(EstimateGenerationSession $session, int $expectedVersion): SessionActionResult
    {
        return $this->generation->handle(
            $session,
            $expectedVersion,
            is_string($session->input_payload['generation_mode'] ?? null)
                ? $session->input_payload['generation_mode']
                : null,
        );
    }
}
