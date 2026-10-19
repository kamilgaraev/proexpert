<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\EquipmentWorkItemExpander;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EquipmentEstimateScopeTest extends TestCase
{
    private const SOURCE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_contextual_excluded_and_unconfirmed_visual_assets_do_not_become_equipment_work_even_with_a_confirmed_count(): void
    {
        $entities = [$this->entity('native', []), $this->entity('bed', ['estimate_scope' => 'contextual_only']),
            $this->entity('excluded', ['estimate_scope' => 'excluded_by_document_note']), $this->entity('visual'),
            $this->entity('candidate', ['estimate_scope' => 'estimate_candidate'])];
        $count = new Fact('fact:count', 1, 2, 3, self::SOURCE, 'equipment:visual', 'equipment_count', '1', 'count',
            1.0, 'user_assumption', 'confirmed', []);
        $snapshot = new ProjectModelSnapshot($entities, [$count], [], []);
        self::assertSame(['equipment:native' => EquipmentEstimateScope::SUPPLY_AND_INSTALLATION], EquipmentEstimateScope::select($snapshot, []));
    }

    #[DataProvider('acceptedModes')]
    public function test_a_selected_user_answer_scopes_the_referenced_group_including_its_superseded_target(string $mode): void
    {
        $answer = $this->answer('answer:a', 'equipment:a', $mode, ['fact:a', 'fact:b']);
        $snapshot = new ProjectModelSnapshot([$this->entity('a'), $this->entity('b'), $this->entity('neighbor'),
            $this->entity('excluded', ['estimate_scope' => 'excluded_by_document_note'])],
            [$answer, $this->observed('b'), $this->observed('neighbor'), $this->observed('excluded')], [], []);
        self::assertSame(['equipment:a' => $mode, 'equipment:b' => $mode], EquipmentEstimateScope::select($snapshot, [$this->decision($answer, 'fact:a')]));
        self::assertSame([$answer->id], EquipmentEstimateScope::answerFactIds($snapshot));
    }

    public static function acceptedModes(): array
    {
        return [[EquipmentEstimateScope::SUPPLY_AND_INSTALLATION], [EquipmentEstimateScope::INSTALLATION_ONLY]];
    }

    public function test_scope_requires_a_selected_current_user_decision_with_matching_source_and_authorization_scope(): void
    {
        $answer = $this->answer('answer:a', 'equipment:a', EquipmentEstimateScope::SUPPLY_AND_INSTALLATION, ['fact:a']);
        $snapshot = new ProjectModelSnapshot([$this->entity('a')], [$answer], [], []);
        self::assertSame([], EquipmentEstimateScope::select($snapshot, []));
        $unresolved = new Fact($answer->id, 1, 2, 3, self::SOURCE, $answer->entityId, $answer->type, $answer->value,
            null, 1.0, 'unresolved', 'unresolved', [], 2, 'fact:a');
        self::assertSame([], EquipmentEstimateScope::select(new ProjectModelSnapshot([$this->entity('a')], [$unresolved], [], []), [$this->decision($unresolved, 'fact:a')]));
        foreach ([['system', 1, self::SOURCE], ['user', 9, self::SOURCE], ['user', 1, 'sha256:'.str_repeat('b', 64)]] as [$actor, $organization, $source]) {
            $decision = new Decision('decision:invalid', $organization, 2, 3, $source, 'fact', 'fact:a', $answer->id,
                $actor, '1', 'Проверка области решения', 2, []);
            self::assertSame([], EquipmentEstimateScope::select($snapshot, [$decision]));
        }
    }

    #[DataProvider('deniedResponses')]
    public function test_excluded_other_unresolved_and_conflicting_answers_never_enable_purchase(string $response, string $choice): void
    {
        $positive = $this->answer('answer:a', 'equipment:a', EquipmentEstimateScope::SUPPLY_AND_INSTALLATION, ['fact:a', 'fact:b']);
        $negative = $this->answer('answer:b', 'equipment:b', $choice, ['fact:a', 'fact:b'], $response);
        $snapshot = new ProjectModelSnapshot([$this->entity('a'), $this->entity('b')], [$positive, $negative], [], []);
        self::assertSame([], EquipmentEstimateScope::select($snapshot, [$this->decision($positive, 'fact:a'), $this->decision($negative, 'fact:b')]));
    }

    public static function deniedResponses(): array
    {
        return [['selected', 'exclude'], ['other', EquipmentEstimateScope::SUPPLY_AND_INSTALLATION], ['leave_unresolved', 'leave_unresolved']];
    }

    public function test_a_shared_document_evidence_does_not_expand_the_answer_beyond_its_fact_locators(): void
    {
        $answer = $this->answer('answer:neighbor', 'equipment:neighbor', EquipmentEstimateScope::INSTALLATION_ONLY, ['fact:a']);
        $snapshot = new ProjectModelSnapshot([$this->entity('a'), $this->entity('neighbor')], [$this->observed('a'), $answer], [], []);
        self::assertSame(['equipment:a' => EquipmentEstimateScope::INSTALLATION_ONLY],
            EquipmentEstimateScope::select($snapshot, [$this->decision($answer, 'fact:neighbor')]));
    }

    public function test_installation_only_removes_generic_purchase_and_keeps_the_work_count_used_by_the_quote(): void
    {
        $scopes = ['equipment:a' => EquipmentEstimateScope::INSTALLATION_ONLY, 'equipment:b' => EquipmentEstimateScope::INSTALLATION_ONLY];
        $items = [];
        foreach (['purchase', 'install', 'delivery'] as $work) {
            $items[] = ['key' => $work, 'item_type' => 'priced_work', 'quantity' => null, 'metadata' => [
                'composition_work_key' => 'equipment.'.$work, 'generation_source' => 'qualitative_work_catalog',
                'residential_equipment_candidate' => true, 'quantity_source' => 'unknown']];
        }
        $draft = ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
        $expanded = (new EquipmentWorkItemExpander)->expand($draft, [], 3, $scopes);
        $rows = $expanded['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(EquipmentEstimateScope::workCount($scopes) + 1, $rows);
        self::assertSame(['equipment.install', 'equipment.install', 'equipment.delivery'], array_column(array_column($rows, 'metadata'), 'composition_work_key'));
    }

    private function entity(string $key, array $properties = ['estimate_scope' => 'requires_confirmation', 'visual_inventory_category' => 'sanitary_fixture']): Entity
    {
        return new Entity('equipment:'.$key, 1, 2, 3, self::SOURCE, 'equipment', 'equipment:'.$key, ['properties' => $properties]);
    }

    private function observed(string $key): Fact
    {
        return new Fact('fact:'.$key, 1, 2, 3, self::SOURCE, 'equipment:'.$key, 'sanitary_fixture', $key, null, 0.7,
            'document', 'candidate', ['evidence:shared']);
    }

    private function answer(string $id, string $entity, string $mode, array $factIds, string $response = 'selected'): Fact
    {
        return new Fact($id, 1, 2, 3, self::SOURCE, $entity, 'sanitary_fixture', [
            'question_key' => 'project_question_group', 'question_fingerprint' => str_repeat('c', 64),
            'response' => $response, 'choice_value' => $mode,
            'source_locator' => ['conflict_id' => 'visual-inventory:room', 'fact_ids' => $factIds],
        ], null, 1.0, $response === 'leave_unresolved' ? 'unresolved' : 'user_assumption',
            $response === 'leave_unresolved' ? 'unresolved' : 'confirmed', ['evidence:shared'], 2, 'fact:'.substr($entity, strlen('equipment:')));
    }

    private function decision(Fact $answer, string $target): Decision
    {
        return new Decision('decision:'.$answer->id, 1, 2, 3, self::SOURCE, 'fact', $target, $answer->id, 'user', '1', 'Подтверждённый состав оборудования', 2, []);
    }
}
