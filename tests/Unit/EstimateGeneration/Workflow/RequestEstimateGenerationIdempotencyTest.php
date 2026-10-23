<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\RequestEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RequestEstimateGenerationIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new \Illuminate\Container\Container;
        $container->instance('config', new \Illuminate\Config\Repository(['estimate-generation' => ['credits' => ['enabled' => false]]]));
        \Illuminate\Container\Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance(null);
        parent::tearDown();
    }

    #[Test]
    public function lost_queued_response_can_be_retried_with_start_version_without_redispatch(): void
    {
        $session = $this->generatingSession(12, 'attempt-owned');

        $result = $this->useCase()->handle($session, 7, null, $this->actor());

        self::assertTrue($result->successful);
        self::assertSame(202, $result->httpStatus);
        self::assertSame(12, $result->session->state_version);
        self::assertSame('attempt-owned', $result->session->input_payload['generation_attempt_id']);
        self::assertSame('local:previous-rebuild', $result->session->input_payload['rebuild_section_key']);
    }

    #[Test]
    public function a_new_full_generation_clears_the_previous_rebuild_scope(): void
    {
        $session = $this->getMockBuilder(EstimateGenerationSession::class)->onlyMethods(['load'])->getMock();
        $session->fill(['organization_id' => 202, 'project_id' => 303, 'status' => EstimateGenerationStatus::EstimateReviewRequired,
            'state_version' => 12, 'input_payload' => ['generation_mode' => 'ai_assisted', 'description' => 'Состав работ для текущего проекта',
                'rebuild_section_key' => 'local:previous-rebuild']]);
        $session->id = 404;
        $session->expects(self::once())->method('load')->with('documents')->willReturnSelf();
        $state = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore::class);
        $state->expects(self::once())->method('compareAndSet')->willReturnCallback(static function ($current, int $version, $status, array $attributes) {
            self::assertSame(12, $version);
            self::assertSame(EstimateGenerationStatus::Generating, $status);
            $current->fill([...$attributes, 'status' => $status, 'state_version' => $version + 1]);

            return $current;
        });
        $advance = new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration(
            new \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow(
                new \App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap, $state));
        $readiness = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService::class);
        $readiness->expects(self::once())->method('evaluate')->with($session)->willReturn(['can_generate' => true, 'summary' => []]);
        $useCase = $this->useCase();
        $reflection = new ReflectionClass(RequestEstimateGeneration::class);
        foreach (['policy' => new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy,
            'advance' => $advance, 'readiness' => $readiness] as $property => $value) {
            $reflection->getProperty($property)->setValue($useCase, $value);
        }
        $dispatcher = $this->createMock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(
            \App\BusinessModules\Addons\EstimateGeneration\Jobs\GenerateEstimateDraftJob::class));
        \Illuminate\Container\Container::getInstance()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        $result = $useCase->handle($session, 12, null, $this->actor());
        self::assertTrue($result->successful);
        self::assertSame(13, $result->session->state_version);
        self::assertArrayHasKey('rebuild_section_key', $result->session->input_payload);
        self::assertNull($result->session->input_payload['rebuild_section_key']);
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
        $authorization = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization::class);
        $reflection = new ReflectionClass(RequestEstimateGeneration::class);
        $useCase = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('authorizer')->setValue(
            $useCase,
            $authorization,
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
            'input_payload' => ['generation_attempt_id' => $attemptId, 'rebuild_section_key' => 'local:previous-rebuild'],
        ]);
    }

    private function actor(): User
    {
        $actor = new User(['current_organization_id' => 202]);
        $actor->id = 101;

        return $actor;
    }
}
