<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Questions\CurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationAnswerRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationChoice;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationQuestion;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications;
use PHPUnit\Framework\TestCase;

final class ListEstimateClarificationsTest extends TestCase
{
    public function test_returns_only_unanswered_safe_questions_with_exact_answer_fences(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $catalog = new FixedClarificationCatalog([
            $this->current('wall_material_required', 'Материал наружных стен', $sourceVersion, 'fact:wall', 'b'),
            $this->current('roof_material_required', 'Материал кровли', $sourceVersion, 'fact:roof', 'c'),
        ]);
        $registry = new FixedListAnswerRegistry(['wall_material_required']);

        $items = (new ListEstimateClarifications($catalog, $registry))->handle(10, 20, 40);

        self::assertCount(1, $items);
        self::assertSame('roof_material_required', $items[0]['code'] ?? null);
        self::assertSame($sourceVersion, $items[0]['source_version'] ?? null);
        self::assertSame(str_repeat('c', 64), $items[0]['answer_fingerprint'] ?? null);
        self::assertSame([4], $items[0]['source_locator']['page_numbers'] ?? null);
        self::assertArrayNotHasKey('target_fact_id', $items[0]);
        self::assertArrayNotHasKey('snapshot_token', $items[0]);
    }

    private function current(
        string $code,
        string $subject,
        string $sourceVersion,
        string $targetFactId,
        string $fingerprintCharacter,
    ): CurrentEstimateClarification {
        return new CurrentEstimateClarification(
            new EstimateClarificationQuestion(
                $code,
                $subject,
                'В документах указаны разные варианты решения.',
                'Выбор изменяет состав работ и стоимость материалов.',
                'Рекомендуется выбрать вариант из основной спецификации.',
                [
                    new EstimateClarificationChoice('select:'.hash('sha256', 'Первый вариант'), 'Первый вариант'),
                    new EstimateClarificationChoice('select:'.hash('sha256', 'Второй вариант'), 'Второй вариант'),
                    new EstimateClarificationChoice('other', 'Другое', 'other'),
                    new EstimateClarificationChoice('leave_unresolved', 'Оставить нерешённым', 'leave_unresolved'),
                ],
                ['page_numbers' => [4]],
            ),
            $sourceVersion,
            str_repeat('d', 64),
            str_repeat($fingerprintCharacter, 64),
            $targetFactId,
        );
    }
}

final readonly class FixedClarificationCatalog implements EstimateClarificationCatalog
{
    /** @param list<CurrentEstimateClarification> $items */
    public function __construct(private array $items) {}

    public function allCurrent(int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->items;
    }
}

final readonly class FixedListAnswerRegistry implements EstimateClarificationAnswerRegistry
{
    /** @param list<string> $keys */
    public function __construct(private array $keys) {}

    public function answeredKeys(int $organizationId, int $projectId, int $sessionId): array
    {
        return $this->keys;
    }
}
