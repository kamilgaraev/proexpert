<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Illuminate\Support\Facades\DB;

final class VisionOutputTokenPolicy
{
    public static function ceiling(): int
    {
        return max(8_192, min(16_384, (int) config('estimate-generation.vision.primary_max_output_tokens', 8_192)));
    }

    public static function forOperation(AiOperationContext $context): int
    {
        if ($context->creditOperationId === null) {
            return self::ceiling();
        }
        $operation = DB::table('estimate_generation_credit_operations')->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)->where('session_id', $context->sessionId)
            ->where('operation_id', $context->creditOperationId)->first(['processing_plan', 'plan_hash']);
        if ($operation === null || ! is_string($operation->processing_plan) || ! is_string($operation->plan_hash)) {
            throw new UsageInvariantViolation('vision_approved_plan_missing');
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($plan) || ! hash_equals($operation->plan_hash, hash('sha256', CanonicalPipelineJson::encode($plan)))) {
            throw new UsageInvariantViolation('vision_approved_plan_invalid');
        }
        $ceiling = $plan['roles']['vision']['output_ceiling'] ?? null;
        if (! is_int($ceiling) || $ceiling < 256 || $ceiling > 16_384) {
            throw new UsageInvariantViolation('vision_approved_output_ceiling_invalid');
        }

        return $ceiling;
    }
}
