<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineFailureDetails;
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
}
