<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\GenerationAttemptGuard;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GenerationAttemptGuardTest extends TestCase
{
    #[Test]
    public function only_the_current_generating_attempt_may_run(): void
    {
        $guard = new GenerationAttemptGuard;
        $session = $this->session(EstimateGenerationStatus::Generating, 7, 'attempt-new');

        self::assertTrue($guard->matches($session, 7, 'attempt-new'));
        self::assertFalse($guard->matches($session, 6, 'attempt-new'));
        self::assertFalse($guard->matches($session, 7, 'attempt-old'));
        self::assertFalse($guard->matches($session, 7, null));
    }

    #[Test]
    public function terminal_or_review_state_rejects_late_handle_and_failure(): void
    {
        $guard = new GenerationAttemptGuard;

        self::assertFalse($guard->matches($this->session(EstimateGenerationStatus::ReadyToApply, 7, 'same'), 7, 'same'));
        self::assertFalse($guard->matches($this->session(EstimateGenerationStatus::Applied, 7, 'same'), 7, 'same'));
    }

    private function session(EstimateGenerationStatus $status, int $version, string $attemptId): EstimateGenerationSession
    {
        return new EstimateGenerationSession([
            'status' => $status,
            'state_version' => $version,
            'input_payload' => ['generation_attempt_id' => $attemptId],
        ]);
    }
}
