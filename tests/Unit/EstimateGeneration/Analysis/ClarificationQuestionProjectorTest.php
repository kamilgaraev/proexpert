<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Questions\ClarificationQuestionProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClarificationQuestionProjectorTest extends TestCase
{
    #[Test]
    public function pages_four_and_eighteen_project_one_concrete_foundation_question_with_complete_choices(): void
    {
        $projector = new ClarificationQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
        $payloads = [
            $this->page(4, 'foundation_type_required', 'Тип фундамента указан условно.', ['Ленточный', 'Плитный']),
            $this->page(18, 'foundation_type_required', 'На визуализации фундамент не подтверждён.', ['Ленточный', 'Плитный']),
        ];

        $questions = $projector->projectPages($payloads);

        self::assertCount(1, $questions);
        self::assertSame('foundation_type_required', $questions[0]->code);
        self::assertSame(['Ленточный', 'Плитный', 'Другое', 'Оставить нерешённым'], array_column($questions[0]->toArray()['choices'], 'label'));
        self::assertSame([4, 18], $questions[0]->toArray()['source_locator']['page_numbers']);
    }

    #[Test]
    public function project_details_own_materials_and_visualization_is_only_corroboration(): void
    {
        $questions = (new ClarificationQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        }))->projectPages([
            $this->page(4, 'facade_material_required', 'В общих данных материал не указан.', ['Штукатурка']),
            $this->page(18, 'facade_material_required', 'На визуализации видна штукатурка.', ['Штукатурка'], 'corroboration'),
            $this->page(19, 'facade_material_required', 'В узле указан материал фасада.', ['Кирпич'], 'explicit_document'),
        ]);

        self::assertCount(1, $questions);
        self::assertSame([4, 18, 19], $questions[0]->toArray()['source_locator']['page_numbers']);
        self::assertSame('explicit_document', $questions[0]->toArray()['source_locator']['authority']);
        self::assertArrayNotHasKey('confidence', $questions[0]->toArray());
    }

    #[Test]
    #[DataProvider('unsafeTexts')]
    public function raw_provider_and_generic_clarification_text_is_rejected(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ClarificationQuestionProjector(static fn (string $key): string => $key))->projectPages([
            $this->page(4, 'invalid_question', $text, ['Да', 'Нет']),
        ]);
    }

    public static function unsafeTexts(): iterable
    {
        yield ['needs clarification'];
        yield ['нужно уточнить'];
        yield ['openai timeout'];
    }

    #[Test]
    public function provider_text_is_rejected_in_subject_and_choice_labels(): void
    {
        $projector = new ClarificationQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
        $invalidSubject = $this->page(4, 'invalid_subject', 'На листе есть расхождение.', ['Да', 'Нет']);
        $invalidSubject['document_arbitration']['questions'][0]['subject'] = 'Timeweb timeout';

        try {
            $projector->projectPages([$invalidSubject]);
            self::fail('Provider subject was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        $projector->projectPages([
            $this->page(4, 'invalid_choice', 'На листе есть расхождение.', ['Да', 'Timeweb timeout']),
        ]);
    }

    #[Test]
    public function canonical_page_questions_include_geometry_questions_without_a_second_projection(): void
    {
        $payload = $this->page(4, 'partial_opening_geometry_abc123', 'На разрезе не указана высота проёма.', ['Указать высоту', 'Не учитывать проём']);
        $payload['ai_questions'] = $payload['document_arbitration']['questions'];
        unset($payload['document_arbitration']);

        $questions = (new ClarificationQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        }))->projectPages([$payload]);

        self::assertCount(1, $questions);
        self::assertSame('partial_opening_geometry_abc123', $questions[0]->code);
        self::assertSame([4], $questions[0]->sourceLocator['page_numbers']);
    }

    /** @return array<string,mixed> */
    private function page(int $page, string $code, string $reason, array $choices, string $authority = 'explicit_document'): array
    {
        return [
            'page_number' => $page,
            'document_arbitration' => [
                'role' => 'arbiter',
                'questions' => [[
                    'code' => $code,
                    'subject' => str_starts_with($code, 'foundation') ? 'Тип фундамента' : 'Материал фасада',
                    'reason' => $reason,
                    'impact' => 'Ответ влияет на состав и объём работ.',
                    'recommendation' => 'Выберите вариант по рабочей документации.',
                    'choices' => $choices,
                    'source_locator' => [
                        'page_number' => $page,
                        'evidence_refs' => ['page-'.$page],
                        'authority' => $authority,
                    ],
                ]],
            ],
        ];
    }
}
