<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use PHPUnit\Framework\TestCase;

final class AssistantRequestProjectScopeTest extends TestCase
{
    public function test_current_object_uses_only_one_trusted_project_reference(): void
    {
        foreach (['на этом объекте', 'на текущем объекте', 'в выбранном проекте', 'в данном проекте', 'на нашем объекте', 'нашего объекта', 'наш проект', 'по нашему проекту'] as $scope) {
            $resolved = AssistantRequestProjectScope::fromPlan($this->plan('Что у нас по договорам '.$scope.'?'));
            self::assertTrue($resolved->requested);
            self::assertSame(52, $resolved->projectId);
        }
        foreach ([['project_id' => 52], ['entity_refs' => [['type' => 'project', 'id' => 52], ['type' => 'project', 'id' => 100]]]] as $context) {
            $resolved = AssistantRequestProjectScope::fromPlan(['request' => ['message' => 'Договоры на этом объекте?', 'context' => $context]]);
            self::assertTrue($resolved->requested);
            self::assertNull($resolved->projectId);
            self::assertFalse($resolved->allowsReferences([]));
        }
    }

    public function test_explicit_organization_named_and_identifier_queries_are_not_forced_to_ui_project(): void
    {
        foreach (['Покажи договоры во всех проектах', 'Выбери договор любого из наших проектов',
            'Покажи договоры проекта «СевГУ»', 'Покажи договор №315',
            'Покажи договоры в наших проектах', 'Покажи наши проекты', 'Выбери любой наш объект', 'Договоры из наших объектов',
            'Сравни договоры в этом проекте и во всех проектах'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::fromPlan($this->plan($question))->requested);
        }
    }

    public function test_expenses_and_overdue_tasks_on_our_object_share_trusted_scope(): void
    {
        foreach (['Есть на нашем объекте просроченные задачи?', 'Покажи расходы нашего объекта.'] as $question) {
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertTrue($scope->requested);
            self::assertSame(52, $scope->projectId);
            self::assertFalse($scope->allowsReferences([['entity_type' => 'payment_document', 'project_id' => 100]]));
        }
    }

    public function test_foreign_project_rows_counts_and_selection_are_rejected(): void
    {
        $scope = AssistantRequestProjectScope::fromPlan($this->plan('Договоры на этом объекте?'));
        self::assertTrue($scope->allowsReferences([['entity_type' => 'project', 'entity_id' => 52, 'project_id' => null]]));
        foreach ([['entity_type' => 'project', 'entity_id' => 100],
            ['entity_type' => 'contract', 'entity_id' => 315, 'project_id' => 100],
            ['entity_type' => 'live_domain_search_count', 'project_id' => null],
            ['entity_type' => 'contractor', 'entity_id' => 202]] as $reference) {
            self::assertFalse($scope->allowsReferences([$reference]));
            self::assertSame([], $scope->filterResults([['source_refs' => [$reference]]]));
        }
        self::assertTrue($scope->allowsReferences([['entity_type' => 'live_domain_search_count', 'project_id' => 52]]));
    }

    public function test_native_financial_projection_uses_its_closed_composite_project_identity(): void
    {
        $scope = AssistantRequestProjectScope::fromPlan($this->plan('Расходы нашего объекта за сентябрь 2025 года'));
        $reference = ['entity_type' => 'live_project_financial_projection', 'projection_name' => 'live_project_financial_projection',
            'entity_id' => 52, 'composite_key' => ['project_id' => 52, 'period_start' => '2025-09-01']];
        self::assertTrue($scope->allowsReferences([$reference]));
        foreach ([['entity_id' => 100], ['project_id' => 100], ['projection_name' => 'unknown'],
            ['entity_type' => 'contract'], ['composite_key' => ['project_id' => 100]]] as $changes) {
            self::assertFalse($scope->allowsReferences([array_replace($reference, $changes)]));
        }
        self::assertNull(AssistantRequestProjectScope::fromPlan($this->plan('Расходы по всем проектам'))->projectId);
    }

    public function test_projectless_party_requires_checked_fk_from_a_current_project_contract(): void
    {
        $scope = AssistantRequestProjectScope::fromPlan($this->plan('Подрядчик договора на этом объекте?'));
        $party = ['entity_type' => 'contractor', 'entity_id' => 202, 'project_id' => null];
        $contract = ['results' => [['entity_type' => 'contract', 'id' => 315, 'fields' => ['contractor_id' => 202]]],
            'source_refs' => [['entity_type' => 'contract', 'entity_id' => 315, 'project_id' => 52, 'checked_fields' => ['contractor_id']]]];
        self::assertTrue($scope->allowsReferences([$party], [$contract]));
        self::assertSame([$party, $contract['source_refs'][0]], $scope->withAnchorReferences([$party], [$contract]));
        self::assertSame([$party, $contract['source_refs'][0]], $scope->withAnchorReferences([$party, $contract['source_refs'][0]], [$contract]));
        $contract['source_refs'][0]['project_id'] = 100;
        self::assertFalse($scope->allowsReferences([$party], [$contract]));
        $contract['source_refs'][0]['project_id'] = 52;
        $contract['source_refs'][0]['checked_fields'] = ['id'];
        self::assertFalse($scope->allowsReferences([$party], [$contract]));
    }

    private function plan(string $question): array
    {
        return ['request' => ['message' => $question, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }
}
