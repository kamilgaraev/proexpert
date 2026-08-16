<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Questions;

use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectUnderstandingQuestionProjectorTest extends TestCase
{
    private const UNDERSTANDING_SOURCE_VERSION = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    #[Test]
    #[DataProvider('damagedOptionProvider')]
    public function one_damaged_option_is_quarantined_without_hiding_the_question(array $damagedOption): void
    {
        $questions = $this->projector()->project([
            $this->question([
                $this->option('select:fact:wall:valid', 'Газобетон'),
                $damagedOption,
            ]),
        ], self::UNDERSTANDING_SOURCE_VERSION);

        self::assertCount(1, $questions);
        self::assertSame(
            ['Газобетон', 'Другое', 'Оставить нерешённым'],
            array_column($questions[0]->toArray()['choices'], 'label'),
        );
        self::assertSame([
            ['type' => 'choice_quarantined', 'count' => 1],
        ], $questions[0]->sourceLocator['limitations']);
        self::assertSame('conflict:wall-material', $questions[0]->sourceLocator['conflict_id']);
        self::assertSame(['fact:wall'], $questions[0]->sourceLocator['fact_ids']);
        self::assertSame(['evidence:page:4'], $questions[0]->sourceLocator['evidence_ids']);
        self::assertSame([4], $questions[0]->sourceLocator['page_numbers']);
        self::assertSame(self::UNDERSTANDING_SOURCE_VERSION, $questions[0]->sourceLocator['understanding_source_version']);
        $damagedLabel = (string) ($damagedOption['label'] ?? '');
        if ($damagedLabel !== '') {
            self::assertStringNotContainsString($damagedLabel, json_encode($questions[0]->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function multiple_damaged_options_do_not_remove_multiple_valid_options(): void
    {
        $questions = $this->projector()->project([
            $this->question([
                $this->option('select:fact:wall:a', 'Газобетон'),
                $this->option('select:fact:wall:empty', ''),
                $this->option('select:fact:wall:b', 'Керамический блок'),
                $this->option('select:fact:wall:technical', 'Timeweb provider timeout'),
                $this->option('select:fact:wall:english', 'Concrete block'),
            ]),
        ], self::UNDERSTANDING_SOURCE_VERSION);

        self::assertCount(1, $questions);
        self::assertSame(
            ['Газобетон', 'Керамический блок', 'Другое', 'Оставить нерешённым'],
            array_column($questions[0]->toArray()['choices'], 'label'),
        );
        self::assertSame([
            ['type' => 'choice_quarantined', 'count' => 3],
        ], $questions[0]->sourceLocator['limitations']);
    }

    #[Test]
    public function all_damaged_ai_options_leave_the_question_and_system_answers_available(): void
    {
        $questions = $this->projector()->project([
            $this->question([
                $this->option('select:fact:wall:empty', ''),
                $this->option('select:fact:wall:technical', 'provider fallback timeout'),
                $this->option('select:fact:wall:english', 'Concrete block'),
            ]),
        ], self::UNDERSTANDING_SOURCE_VERSION);

        self::assertCount(1, $questions);
        self::assertSame(
            ['Другое', 'Оставить нерешённым'],
            array_column($questions[0]->toArray()['choices'], 'label'),
        );
        self::assertSame([
            ['type' => 'choice_quarantined', 'count' => 3],
        ], $questions[0]->sourceLocator['limitations']);
    }

    public static function damagedOptionProvider(): iterable
    {
        yield 'cyrillic label over 160 characters' => [
            ['value' => 'select:fact:wall:long', 'fact_id' => 'fact:wall', 'label' => str_repeat('Я', 161)],
        ];
        yield 'empty label' => [
            ['value' => 'select:fact:wall:empty', 'fact_id' => 'fact:wall', 'label' => ''],
        ];
        yield 'technical label' => [
            ['value' => 'select:fact:wall:technical', 'fact_id' => 'fact:wall', 'label' => 'Timeweb provider timeout'],
        ];
        yield 'non-Russian label' => [
            ['value' => 'select:fact:wall:english', 'fact_id' => 'fact:wall', 'label' => 'Concrete block'],
        ];
    }

    private function projector(): ProjectUnderstandingQuestionProjector
    {
        return new ProjectUnderstandingQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
    }

    /** @param list<array<string, mixed>> $options */
    private function question(array $options): array
    {
        return [
            'conflict_id' => 'conflict:wall-material',
            'text' => 'Какой материал наружных стен использовать?',
            'reason' => 'В документах указаны разные материалы наружных стен.',
            'impact' => 'Выбор материала влияет на объёмы и стоимость работ.',
            'recommendation' => 'Выберите подтверждённый материал или оставьте вопрос нерешённым.',
            'fact_ids' => ['fact:wall'],
            'evidence_ids' => ['evidence:page:4'],
            'source_locator' => ['page_numbers' => [4]],
            'options' => $options,
        ];
    }

    /** @return array<string, string> */
    private function option(string $value, string $label): array
    {
        return ['value' => $value, 'fact_id' => 'fact:wall', 'label' => $label];
    }
}
