<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\VisualInventoryQuestionBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnswerEstimateClarificationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ResolveCurrentEstimateClarification;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function specification_and_count_confirmation_keep_the_scope_question_targeting_only_visual_facts(): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $entity = $this->entity('equipment:bath', $source, 'Унитаз', 'sanitary_fixture', 'room:bathroom');
        $questions = $this->builder()->build([$entity], [
            $this->fact('fact:plan', $entity, $source, 'sanitary_fixture', 'candidate'),
            $this->fact('fact:spec', $entity, $source, 'equipment', 'confirmed'),
            new Fact('fact:count', 1, 2, 3, $source, $entity->id, 'equipment_count', '1', 'count', 1.0, 'user_assumption', 'confirmed', []),
        ], [new Evidence('evidence:page5', 1, 2, 3, $source, 'document:177', 'document', 5)]);

        self::assertCount(1, $questions);
        self::assertSame(['fact:plan'], $questions[0]['fact_ids']);
    }

    #[Test]
    public function every_published_inventory_choice_is_accepted_by_the_answer_request(): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $entity = $this->entity('equipment:toilet', $source, 'Унитаз', 'sanitary_fixture', 'room:bathroom');
        $questions = $this->builder()->build([$entity], [
            $this->fact('fact:toilet', $entity, $source, 'sanitary_fixture', 'candidate'),
        ], [new Evidence('evidence:page5', 1, 2, 3, $source, 'document:177', 'document', 5)]);
        $projector = new ProjectUnderstandingQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
        $question = $projector->project($questions, $source)[0];
        $validator = new Factory(new Translator(new ArrayLoader, 'ru'));
        foreach ($question->choices as $choice) {
            $input = [
                'response' => $choice->value,
                'idempotency_key' => 'inventory-answer-0001',
                'expected_source_version' => $source,
                'answer_fingerprint' => str_repeat('b', 64),
            ];
            if ($choice->kind === 'other') {
                $input['other'] = 'Комплектация уточнена';
            }
            $validation = $validator->make($input, (new AnswerEstimateClarificationRequest)->rules());
            self::assertTrue($validation->passes(), $choice->value.': '.json_encode($validation->errors()->toArray()));
        }
    }

    #[Test]
    #[DataProvider('savedResponses')]
    public function a_trusted_group_answer_suppresses_all_answered_assets_without_hiding_a_new_neighbor(string $response, string $choice): void
    {
        $source = 'sha256:'.str_repeat('a', 64);
        $entities = array_map(fn (string $key): Entity => $this->entity('equipment:'.$key, $source, $key, 'sanitary_fixture', 'room:bathroom'), ['a', 'b', 'neighbor']);
        $answer = new Fact('answer:a', 1, 2, 3, $source, $entities[0]->id, 'sanitary_fixture', [
            'question_key' => 'project_question_group', 'question_fingerprint' => str_repeat('b', 64),
            'response' => $response, 'choice_value' => $choice,
            'source_locator' => ['conflict_id' => 'visual-inventory:group', 'fact_ids' => ['fact:a', 'fact:b']],
        ], null, 1.0, $response === 'leave_unresolved' ? 'unresolved' : 'user_assumption',
            $response === 'leave_unresolved' ? 'unresolved' : 'confirmed', ['evidence:page5'], 2, 'fact:a');
        $facts = [$answer, $this->fact('fact:b', $entities[1], $source, 'sanitary_fixture', 'candidate'),
            $this->fact('fact:neighbor', $entities[2], $source, 'sanitary_fixture', 'candidate')];
        $decision = new Decision('decision:answer', 1, 2, 3, $source, 'fact', 'fact:a', $answer->id, 'user', '1', 'Подтверждение состава', 2);
        $questions = $this->builder()->build($entities, $facts, [], [$decision]);
        self::assertCount(1, $questions);
        self::assertSame(['fact:neighbor'], $questions[0]['fact_ids']);
        $untrusted = $this->builder()->build($entities, $facts, []);
        self::assertSame(['fact:b', 'fact:neighbor'], $untrusted[0]['fact_ids']);
    }

    public static function savedResponses(): array
    {
        return [['selected', 'include_supply_and_installation'], ['selected', 'installation_only'], ['selected', 'exclude'],
            ['other', 'other'], ['leave_unresolved', 'leave_unresolved']];
    }

    #[Test]
    public function assets_in_the_same_room_from_different_source_versions_have_independently_answerable_groups(): void
    {
        $entities = $facts = $evidence = [];
        foreach (['a', 'b'] as $key) {
            $source = 'sha256:'.str_repeat($key, 64);
            $entities[] = $entity = $this->entity('equipment:'.$key, $source, 'Унитаз '.$key, 'sanitary_fixture', 'room:bathroom');
            $facts[] = new Fact('fact:'.$key, 1, 2, 3, $source, $entity->id, 'sanitary_fixture', 'Унитаз', null,
                0.7, 'document', 'candidate', ['evidence:'.$key]);
            $evidence[] = new Evidence('evidence:'.$key, 1, 2, 3, $source, 'document:'.$key, 'document', 1);
        }
        $questions = $this->builder()->build($entities, $facts, $evidence);
        self::assertCount(2, $questions);
        self::assertNotSame($questions[0]['conflict_id'], $questions[1]['conflict_id']);
        self::assertSame([['fact:a'], ['fact:b']], array_column($questions, 'fact_ids'));
        self::assertSame([['evidence:a'], ['evidence:b']], array_column($questions, 'evidence_ids'));
        $resolver = new ResolveCurrentEstimateClarification(new ProjectUnderstandingQuestionProjector(static fn (string $key): string => 'Дополнительный ответ'));
        $current = $resolver->resolveAll($questions, 'sha256:'.str_repeat('c', 64), new ProjectModelSnapshot($entities, $facts, $evidence, []), str_repeat('d', 64));
        self::assertCount(2, $current);
        $decisions = [];
        foreach ($current as $index => $question) {
            self::assertSame($facts[$index]->sourceVersion, $question->sourceVersion);
            $original = $facts[$index];
            $mode = $index === 0 ? EquipmentEstimateScope::INSTALLATION_ONLY : EquipmentEstimateScope::SUPPLY_AND_INSTALLATION;
            $answer = new Fact('answer:'.$index, 1, 2, 3, $original->sourceVersion, $original->entityId, $original->type, [
                'question_key' => $question->question->code, 'question_fingerprint' => str_repeat('e', 64),
                'response' => 'selected', 'choice_value' => $mode, 'source_locator' => $question->question->sourceLocator,
            ], null, 1.0, 'user_assumption', 'confirmed', $original->evidenceIds, 2, $original->id);
            $facts[$index] = $answer;
            $decisions[] = new Decision('decision:'.$index, 1, 2, 3, $original->sourceVersion, 'fact', $original->id, $answer->id,
                'user', '1', 'Подтверждение отдельной версии источника', 2);
            $remaining = $this->builder()->build($entities, $facts, $evidence, $decisions);
            self::assertCount(1 - $index, $remaining);
            if ($index === 0) {
                self::assertSame(['fact:b'], $remaining[0]['fact_ids']);
            }
        }
        self::assertSame(['equipment:a' => EquipmentEstimateScope::INSTALLATION_ONLY, 'equipment:b' => EquipmentEstimateScope::SUPPLY_AND_INSTALLATION],
            EquipmentEstimateScope::select(new ProjectModelSnapshot($entities, $facts, $evidence, []), $decisions));
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
