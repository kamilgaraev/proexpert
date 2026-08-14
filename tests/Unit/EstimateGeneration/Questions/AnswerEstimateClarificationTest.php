<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Questions\AnswerEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Questions\CurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationChoice;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationQuestion;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationSource;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class AnswerEstimateClarificationTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_recommended_choice_is_persisted_through_the_canonical_decision_boundary_and_reanalysis(): void
    {
        $models = new InMemoryProjectModelRepository;
        $models->saveSourceModel(
            [$this->entity()],
            [$this->targetFact()],
            [],
        );
        $current = new CurrentEstimateClarification(
            question: $this->question(),
            sourceVersion: self::SOURCE_VERSION,
            snapshotToken: str_repeat('b', 64),
            answerFingerprint: str_repeat('c', 64),
            targetFactId: 'fact:wall-material',
        );
        $source = new FixedClarificationSource($current);
        $reanalysis = new RecordingPlanningReanalysisTrigger;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->expects(self::once())->method('can')->willReturn(true);
        $service = new AnswerEstimateClarification(
            $authorization,
            $source,
            $models,
            new ApplyProjectModelDecision($models),
            $reanalysis,
            static fn (string $key): string => $key,
        );

        $answer = $service->handle(
            $this->actor(),
            $this->session(),
            new ActorContext(10, 20, 30, 'question-answer-0001', self::SOURCE_VERSION, str_repeat('c', 64)),
            'wall_material_required',
            'select:'.hash('sha256', 'Газобетон'),
        );

        self::assertSame('answered', $answer->status);
        self::assertSame('Газобетон', $answer->choiceLabel);
        self::assertSame(['page_numbers' => [4]], $answer->sourceLocator);
        self::assertCount(1, $models->decisions);
        $decision = array_values($models->decisions)[0];
        self::assertSame('fact:wall-material', $decision->targetId);
        $selected = $models->fact(10, 20, 40, (string) $decision->selectedFactId);
        self::assertInstanceOf(Fact::class, $selected);
        self::assertSame('wall_material_required', $selected->value['question_key'] ?? null);
        self::assertSame('selected', $selected->value['response'] ?? null);
        self::assertSame('Газобетон', $selected->value['choice_label'] ?? null);
        self::assertSame('confirmed', $selected->status);
        self::assertSame(1, $reanalysis->calls);
    }

    public function test_answer_without_exact_question_fences_is_rejected(): void
    {
        $models = new InMemoryProjectModelRepository;
        $models->saveSourceModel([$this->entity()], [$this->targetFact()], []);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $service = new AnswerEstimateClarification(
            $authorization,
            new FixedClarificationSource(new CurrentEstimateClarification(
                $this->question(),
                self::SOURCE_VERSION,
                str_repeat('b', 64),
                str_repeat('c', 64),
                'fact:wall-material',
            )),
            $models,
            new ApplyProjectModelDecision($models),
            new RecordingPlanningReanalysisTrigger,
            static fn (string $key): string => $key,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('estimate_generation.question_fence_required');

        $service->handle(
            $this->actor(),
            $this->session(),
            new ActorContext(10, 20, 30, 'question-answer-0002'),
            'wall_material_required',
            'select:'.hash('sha256', 'Газобетон'),
        );
    }

    public function test_retry_after_post_commit_reanalysis_failure_replays_without_requiring_the_question_to_remain_current(): void
    {
        $models = new InMemoryProjectModelRepository;
        $models->saveSourceModel([$this->entity()], [$this->targetFact()], []);
        $current = new CurrentEstimateClarification(
            $this->question(),
            self::SOURCE_VERSION,
            str_repeat('b', 64),
            str_repeat('c', 64),
            'fact:wall-material',
        );
        $source = new VanishingClarificationSource($current);
        $trigger = new FailingOncePlanningReanalysisTrigger;
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $service = new AnswerEstimateClarification(
            $authorization,
            $source,
            $models,
            new ApplyProjectModelDecision($models),
            $trigger,
            static fn (string $key): string => $key,
        );
        $context = new ActorContext(10, 20, 30, 'question-answer-0003', self::SOURCE_VERSION, str_repeat('c', 64));
        $choice = 'select:'.hash('sha256', 'Газобетон');

        try {
            $service->handle($this->actor(), $this->session(), $context, 'wall_material_required', $choice);
            self::fail('First reanalysis must fail after decision persistence.');
        } catch (RuntimeException $exception) {
            self::assertSame('reanalysis_temporarily_failed', $exception->getMessage());
        }

        $answer = $service->handle($this->actor(), $this->session(), $context, 'wall_material_required', $choice);

        self::assertSame('answered', $answer->status);
        self::assertSame('Газобетон', $answer->choiceLabel);
        self::assertCount(1, $models->decisions);
        self::assertSame(1, $source->calls);
        self::assertSame(2, $trigger->calls);
    }

    public function test_other_and_leave_unresolved_are_persisted_as_explicit_user_decisions(): void
    {
        foreach ([
            ['response' => 'other', 'other' => 'Монолитный железобетон', 'status' => 'confirmed', 'answer_status' => 'answered'],
            ['response' => 'leave_unresolved', 'other' => null, 'status' => 'unresolved', 'answer_status' => 'left_unresolved'],
        ] as $index => $case) {
            $models = new InMemoryProjectModelRepository;
            $models->saveSourceModel([$this->entity()], [$this->targetFact()], []);
            $authorization = $this->createMock(AuthorizationService::class);
            $authorization->method('can')->willReturn(true);
            $service = new AnswerEstimateClarification(
                $authorization,
                new FixedClarificationSource(new CurrentEstimateClarification(
                    $this->question(), self::SOURCE_VERSION, str_repeat('b', 64), str_repeat('c', 64), 'fact:wall-material',
                )),
                $models,
                new ApplyProjectModelDecision($models),
                new RecordingPlanningReanalysisTrigger,
                static fn (string $key): string => $key,
            );

            $answer = $service->handle(
                $this->actor(),
                $this->session(),
                new ActorContext(10, 20, 30, 'question-answer-variant-'.($index + 1), self::SOURCE_VERSION, str_repeat('c', 64)),
                'wall_material_required',
                $case['response'],
                $case['other'],
            );

            $decision = array_values($models->decisions)[0];
            $selected = $models->fact(10, 20, 40, (string) $decision->selectedFactId);
            self::assertSame($case['answer_status'], $answer->status);
            self::assertSame($case['status'], $selected?->status);
            self::assertSame($case['other'], $selected?->value['other'] ?? null);
        }
    }

    public function test_stale_question_and_cross_tenant_actor_are_rejected_before_mutation(): void
    {
        $models = new InMemoryProjectModelRepository;
        $models->saveSourceModel([$this->entity()], [$this->targetFact()], []);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn(true);
        $service = new AnswerEstimateClarification(
            $authorization,
            new FixedClarificationSource(new CurrentEstimateClarification(
                $this->question(), self::SOURCE_VERSION, str_repeat('b', 64), str_repeat('c', 64), 'fact:wall-material',
            )),
            $models,
            new ApplyProjectModelDecision($models),
            new RecordingPlanningReanalysisTrigger,
            static fn (string $key): string => $key,
        );

        try {
            $service->handle(
                $this->actor(),
                $this->session(),
                new ActorContext(10, 20, 30, 'question-answer-stale', self::SOURCE_VERSION, str_repeat('d', 64)),
                'wall_material_required',
                'select:'.hash('sha256', 'Газобетон'),
            );
            self::fail('Stale question must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('estimate_generation.question_stale', $exception->getMessage());
        }

        $foreignActor = (new User)->forceFill(['id' => 30, 'current_organization_id' => 11]);
        $this->expectException(AuthorizationException::class);
        $service->handle(
            $foreignActor,
            $this->session(),
            new ActorContext(10, 20, 30, 'question-answer-foreign', self::SOURCE_VERSION, str_repeat('c', 64)),
            'wall_material_required',
            'select:'.hash('sha256', 'Газобетон'),
        );
    }

    private function question(): EstimateClarificationQuestion
    {
        return new EstimateClarificationQuestion(
            'wall_material_required',
            'Материал наружных стен',
            'В документах указаны разные материалы стен.',
            'Выбор изменяет состав кладочных работ и стоимость материалов.',
            'Рекомендуется выбрать материал из основной спецификации.',
            [
                new EstimateClarificationChoice('select:'.hash('sha256', 'Газобетон'), 'Газобетон'),
                new EstimateClarificationChoice('select:'.hash('sha256', 'Керамический блок'), 'Керамический блок'),
                new EstimateClarificationChoice('other', 'Другое', 'other'),
                new EstimateClarificationChoice('leave_unresolved', 'Оставить нерешённым', 'leave_unresolved'),
            ],
            ['page_numbers' => [4]],
        );
    }

    private function actor(): User
    {
        return (new User)->forceFill(['id' => 30, 'current_organization_id' => 10]);
    }

    private function session(): EstimateGenerationSession
    {
        return (new EstimateGenerationSession)->forceFill(['id' => 40, 'organization_id' => 10, 'project_id' => 20]);
    }

    private function entity(): Entity
    {
        return new Entity('entity:wall', 10, 20, 40, self::SOURCE_VERSION, 'wall', 'wall:external');
    }

    private function targetFact(): Fact
    {
        return new Fact(
            'fact:wall-material',
            10,
            20,
            40,
            self::SOURCE_VERSION,
            'entity:wall',
            'wall_material',
            null,
            null,
            0.0,
            'unresolved',
            'unresolved',
            [],
        );
    }
}

final readonly class FixedClarificationSource implements EstimateClarificationSource
{
    public function __construct(private CurrentEstimateClarification $current) {}

    public function findCurrent(int $organizationId, int $projectId, int $sessionId, string $questionKey): ?CurrentEstimateClarification
    {
        return $this->current;
    }
}

final class RecordingPlanningReanalysisTrigger implements PlanningReanalysisTrigger
{
    public int $calls = 0;

    public function trigger(int $sessionId, ActorContext $context): void
    {
        $this->calls++;
    }
}

final class VanishingClarificationSource implements EstimateClarificationSource
{
    public int $calls = 0;

    public function __construct(private readonly CurrentEstimateClarification $current) {}

    public function findCurrent(int $organizationId, int $projectId, int $sessionId, string $questionKey): ?CurrentEstimateClarification
    {
        $this->calls++;

        return $this->calls === 1 ? $this->current : null;
    }
}

final class FailingOncePlanningReanalysisTrigger implements PlanningReanalysisTrigger
{
    public int $calls = 0;

    public function trigger(int $sessionId, ActorContext $context): void
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new RuntimeException('reanalysis_temporarily_failed');
        }
    }
}
