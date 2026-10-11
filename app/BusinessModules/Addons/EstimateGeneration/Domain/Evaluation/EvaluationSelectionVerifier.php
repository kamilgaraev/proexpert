<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class EvaluationSelectionVerifier
{
    public function accepted(ProjectModelSnapshot $snapshot, array $decisions, mixed $selection, string $type, array $expected): bool
    {
        if (! is_array($selection) || ! is_string($selection['fact_id'] ?? null)) {
            return false;
        }
        $fact = array_column($snapshot->facts, null, 'id')[$selection['fact_id']] ?? null;
        $decision = array_column($decisions, null, 'selectedFactId')[$selection['fact_id']] ?? null;
        if ($fact === null || $decision === null || $fact->type !== $type || ! (new EvaluationChoiceProof)->accepted($snapshot, $fact, $decision)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $fact->value) || ! array_key_exists($key, $selection)
                || CanonicalPipelineJson::encode([$fact->value[$key]]) !== CanonicalPipelineJson::encode([$value])
                || CanonicalPipelineJson::encode([$selection[$key]]) !== CanonicalPipelineJson::encode([$value])) {
                return false;
            }
        }

        return true;
    }
}
