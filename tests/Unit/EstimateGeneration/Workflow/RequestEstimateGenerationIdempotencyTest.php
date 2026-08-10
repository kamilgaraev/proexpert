<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RequestEstimateGenerationIdempotencyTest extends TestCase
{
    #[Test]
    public function lost_queued_response_can_be_retried_with_start_version_without_redispatch(): void
    {
        $session = $this->generatingSession(12, 'attempt-owned');

        $result = $this->useCase()->handle($session, 7, null, $this->actor());

        self::assertTrue($result->successful);
        self::assertSame(202, $result->httpStatus);
        self::assertSame(12, $result->session->state_version);
        self::assertSame('attempt-owned', $result->session->input_payload['generation_attempt_id']);
    }

    #[Test]
    public function generating_state_without_attempt_token_is_a_conflict(): void
    {
        $this->expectException(InvalidEstimateGenerationState::class);

        $this->useCase()->handle($this->generatingSession(12, ''), 12, null, $this->actor());
    }

    #[Test]
    public function selected_price_source_is_not_silently_ignored_during_generation(): void
    {
        $this->expectException(InvalidEstimateGenerationState::class);

        $this->useCase()->handle($this->generatingSession(12, 'attempt-owned'), 12, null, $this->actor(), 150);
    }

    private function useCase(): RequestEstimateGeneration
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $reflection = new ReflectionClass(RequestEstimateGeneration::class);
        $useCase = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('authorizer')->setValue(
            $useCase,
            new EstimateGenerationActionAuthorizer($authorization),
        );

        return $useCase;
    }

    private function generatingSession(int $version, string $attemptId): EstimateGenerationSession
    {
        return new EstimateGenerationSession([
            'organization_id' => 202,
            'project_id' => 303,
            'status' => EstimateGenerationStatus::Generating,
            'state_version' => $version,
            'input_payload' => ['generation_attempt_id' => $attemptId],
        ]);
    }

    private function actor(): User
    {
        $actor = new User(['current_organization_id' => 202]);
        $actor->id = 101;

        return $actor;
    }
}
