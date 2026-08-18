<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\VisualInventoryQuestionBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisualInventoryQuestionBuilderTest extends TestCase
{
    #[Test]
    public function sanitary_items_are_grouped_and_contextual_furniture_never_creates_questions(): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $bath = $this->entity('equipment:bath', $source, 'Унитаз', 'sanitary_fixture', 'room:bathroom');
        $sink = $this->entity('equipment:sink', $source, 'Умывальник', 'sanitary_fixture', 'room:bathroom');
        $bed = $this->entity('equipment:bed', $source, '2 кровати', 'furniture', 'room:bedroom');
        $evidence = new Evidence('evidence:page5', 1, 2, 3, $source, 'document:177', 'document', 5);

        $questions = $this->builder()->build(
            [$bath, $sink, $bed],
            [
                $this->fact('fact:toilet', $bath, $source, 'sanitary_fixture', 'candidate'),
                $this->fact('fact:sink', $sink, $source, 'sanitary_fixture', 'candidate'),
                $this->fact('fact:bed', $bed, $source, 'furniture', 'candidate'),
            ],
            [$evidence],
        );

        self::assertCount(1, $questions);
        self::assertSame(['fact:toilet', 'fact:sink'], $questions[0]['fact_ids']);
        self::assertSame([5], $questions[0]['source_locator']['page_numbers']);
        self::assertStringContainsString('Унитаз, Умывальник', $questions[0]['text']);
        self::assertCount(3, $questions[0]['options']);
        $questionProjector = new ProjectUnderstandingQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
        $projected = $questionProjector->project($questions, $source);
        self::assertCount(1, $projected);
        self::assertSame(
            ['Включить поставку и монтаж', 'Учесть только монтаж', 'Не включать в смету', 'Другое', 'Оставить нерешённым'],
            array_column($projected[0]->toArray()['choices'], 'label'),
        );
    }

    #[Test]
    public function specification_confirmation_suppresses_the_question_without_another_vision_run(): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $entity = $this->entity('equipment:bath', $source, 'Унитаз', 'sanitary_fixture', 'room:bathroom');
        $questions = $this->builder()->build([$entity], [
            $this->fact('fact:plan', $entity, $source, 'sanitary_fixture', 'candidate'),
            $this->fact('fact:spec', $entity, $source, 'equipment', 'confirmed'),
        ], [new Evidence('evidence:page5', 1, 2, 3, $source, 'document:177', 'document', 5)]);

        self::assertSame([], $questions);
    }

    private function builder(): VisualInventoryQuestionBuilder
    {
        return new VisualInventoryQuestionBuilder(static fn (string $key, array $replace = []): string => match ($key) {
            'estimate_generation.project_model.visual_inventory.question' => 'На плане обнаружены: '.$replace['items'].'. Включить поставку и монтаж?',
            'estimate_generation.project_model.visual_inventory.include' => 'Включить поставку и монтаж',
            'estimate_generation.project_model.visual_inventory.installation_only' => 'Учесть только монтаж',
            'estimate_generation.project_model.visual_inventory.exclude' => 'Не включать в смету',
            'estimate_generation.project_model.visual_inventory.reason' => 'Комплектация не подтверждена спецификацией.',
            'estimate_generation.project_model.visual_inventory.impact' => 'Решение влияет на работы и стоимость.',
            'estimate_generation.project_model.visual_inventory.recommendation' => 'Подтвердите состав поставки и монтажа.',
            default => $key,
        });
    }

    private function entity(string $id, string $source, string $name, string $category, string $room): Entity
    {
        return new Entity($id, 1, 2, 3, $source, 'equipment', $id, [
            'name' => $name,
            'properties' => ['visual_inventory_category' => $category, 'estimate_scope' => 'requires_confirmation', 'room_key' => $room],
        ]);
    }

    private function fact(string $id, Entity $entity, string $source, string $type, string $status): Fact
    {
        return new Fact($id, 1, 2, 3, $source, $entity->id, $type, $entity->attributes['name'], null, 0.9, 'document', $status, ['evidence:page5']);
    }
}
