<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineFailureDetails;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PipelineFailureDetailsTest extends TestCase
{
    #[Test]
    public function persisted_failure_details_never_contain_exception_secrets(): void
    {
        $secrets = [
            'Bearer secret-access-token',
            'api_key=private-key',
            'https://storage.test/file?X-Amz-Signature=signed-value',
            'password=plain-secret',
        ];
        $details = PipelineFailureDetails::from(new RuntimeException(implode(' ', $secrets), 503));
        $persisted = implode('|', [$details->code, $details->fingerprint]);

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $persisted);
        }
        self::assertSame('pipeline_stage_failed', $details->code);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $details->fingerprint);
        self::assertSame(64, strlen($details->fingerprint));
    }

    #[Test]
    public function diagnostic_fingerprint_never_depends_on_throwable_message(): void
    {
        $first = PipelineFailureDetails::from(new RuntimeException('first private document', 503));
        $second = PipelineFailureDetails::from(new RuntimeException('second private document', 503));

        self::assertSame($first->fingerprint, $second->fingerprint);
    }

    #[Test]
    public function previous_chain_fingerprint_is_deterministic_and_contains_no_messages(): void
    {
        $firstPrevious = new LogicException('first private document', 41);
        $secondPrevious = new RuntimeException('second private document', 73, $firstPrevious);
        $error = new RuntimeException('outer private document', 500, $secondPrevious);

        $fingerprint = PipelineFailureDetails::previousChainFingerprint($error);

        self::assertSame(
            hash('sha256', implode("\0", [
                PipelineFailureDetails::from($secondPrevious)->fingerprint,
                PipelineFailureDetails::from($firstPrevious)->fingerprint,
            ])),
            $fingerprint,
        );
        self::assertStringNotContainsString('private document', (string) $fingerprint);
        self::assertNull(PipelineFailureDetails::previousChainFingerprint(new RuntimeException('no previous')));
    }
}
