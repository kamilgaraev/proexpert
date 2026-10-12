<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class EloquentProjectSynthesisAllowance implements ProjectSynthesisAllowance
{
    public function partLimit(ProjectSynthesisInput $input): ?int
    {
        if ($input->wireScope?->creditOperationId === null) {
            return null;
        }
        $operation = DB::table('estimate_generation_credit_operations')
            ->where('organization_id', $input->organizationId)->where('project_id', $input->projectId)
            ->where('session_id', $input->sessionId)->where('operation_id', $input->wireScope->creditOperationId)->first(['processing_plan']);
        if ($operation === null) {
            throw new InvalidArgumentException('project_synthesis_approved_plan_missing');
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        $role = $plan['roles']['project_synthesis'] ?? null;
        $limit = $plan['synthesis_part_limit'] ?? ($role === null ? 0 : 1);
        if (! is_int($limit) || $limit < 0 || $limit > 64 || ($limit > 0 && ! is_array($role))) {
            throw new InvalidArgumentException('project_synthesis_approved_plan_invalid');
        }

        return $limit;
    }
}
