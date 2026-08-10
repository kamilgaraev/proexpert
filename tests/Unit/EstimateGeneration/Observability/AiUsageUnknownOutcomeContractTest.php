<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiUsageUnknownOutcomeContractTest extends TestCase
{
    #[Test]
    public function ambiguous_attempt_is_unmeasured_and_status_and_request_context_are_fingerprinted(): void
    {
        $succeeded = $this->usage('succeeded');
        $ambiguous = $this->usage('ambiguous');
        $targeted = $this->usage('ambiguous', [
            'contract_version' => 'targeted-sheet-recheck:v1',
            'role' => 'plan',
            'reason' => 'sheet_role_insufficient_evidence',
            'source_set' => ['document:4/sheet:5'],
            'entity_key' => 'room-1',
        ]);

        self::assertSame('unavailable', $ambiguous->usageStatus);
        self::assertSame(0, $ambiguous->inputTokens);
        self::assertSame(0, $ambiguous->outputTokens);
        self::assertNull($ambiguous->httpCode);
        self::assertNotSame($succeeded->immutableFingerprint, $ambiguous->immutableFingerprint);
        self::assertNotSame($ambiguous->immutableFingerprint, $targeted->immutableFingerprint);
    }

    /** @param array<string, mixed> $requestContext */
    private function usage(string $status, array $requestContext = []): AiUsageData
    {
        return new AiUsageData(
            context: new AiOperationContext(
                correlationId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
                attemptId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d',
                organizationId: 1,
                projectId: 2,
                sessionId: 3,
                stage: 'understand_documents',
                operation: 'vision',
                attemptOrdinal: 1,
                documentId: 4,
                pageId: 5,
                unitId: 6,
            ),
            provider: 'timeweb',
            requestedModel: 'model-a',
            status: $status,
            durationMs: 1,
            imageCount: 1,
            imageDetail: 'high',
            requestContext: $requestContext,
        );
    }
}
