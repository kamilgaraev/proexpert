<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPhysicalAttemptIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiPhysicalAttemptIdentityTest extends TestCase
{
    #[Test]
    public function identity_is_restart_stable_and_changes_with_the_physical_attempt_contract(): void
    {
        $first = AiPhysicalAttemptIdentity::fromParts('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'vision/model-v1', 1, 'prompt:v1');
        $restarted = AiPhysicalAttemptIdentity::fromParts('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'vision/model-v1', 1, 'prompt:v1');
        $retry = AiPhysicalAttemptIdentity::fromParts('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'vision/model-v1', 2, 'prompt:v1');

        self::assertSame($first, $restarted);
        self::assertNotSame($first, $retry);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first);
    }
}
