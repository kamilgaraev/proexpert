<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use PHPUnit\Framework\TestCase;

final class AiUsageCreditIdentityTest extends TestCase
{
    public function test_legacy_fingerprint_remains_identical_and_a_credit_operation_is_part_of_new_identity(): void
    {
        $context = new AiOperationContext('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d',
            1, 2, 3, 'plan_work_items', 'estimate_composition', 1);
        $legacy = new AiUsageData($context, 'timeweb', 'openai/gpt-6-luna', 'succeeded', 10);
        $oldContext = get_object_vars($context);
        unset($oldContext['creditOperationId']);
        $oldHash = 'sha256:'.hash('sha256', json_encode(['context' => $oldContext, 'provider' => 'timeweb',
            'requested_model' => 'openai/gpt-6-luna', 'reported_model' => null, 'status' => 'succeeded', 'usage_status' => 'unavailable',
            'http_code' => null, 'duration_ms' => 10, 'input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0,
            'reasoning_tokens' => 0, 'image_count' => 0, 'image_detail' => null, 'page_count' => 0, 'price_snapshot' => [],
            'request_context' => []], JSON_THROW_ON_ERROR));
        self::assertSame($oldHash, $legacy->immutableFingerprint);
        $credited = new AiUsageData(new AiOperationContext($context->correlationId, $context->attemptId, 1, 2, 3,
            'plan_work_items', 'estimate_composition', 1, creditOperationId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7e'),
            'timeweb', 'openai/gpt-6-luna', 'succeeded', 10);
        self::assertNotSame($oldHash, $credited->immutableFingerprint);
    }
}
