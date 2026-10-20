<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use PHPUnit\Framework\TestCase;

final class AssistantRequestProjectScopeTest extends TestCase
{
    public function test_all_organization_acts_do_not_inherit_the_project_from_a_negated_limit(): void
    {
        $question = 'Нужна сводка по всем актам организации в CSV: по каждому статусу и каждой валюте — количество актов и общая сумма. Валюты не смешивай, черновики тоже посчитай. Не ограничивайся выбранным проектом.';
        self::assertTrue(AssistantRequestProjectScope::organizationWide($question));
        $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
        self::assertFalse($scope->requested);
        self::assertNull($scope->projectId);
    }

    public function test_record_scopes_keep_organization_negation_and_literal_project_names(): void
    {
        foreach (['По всем актам организации', 'Сводка всех журналов нашей организации',
            'По всем нашим договорам организации', 'По всем сотрудникам нашей организации'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertFalse(AssistantRequestProjectScope::fromPlan($this->plan($question))->requested);
        }
        foreach (['Не всех актов организации, только выбранного проекта', 'Кроме всех журналов нашей организации',
            'По всем актам другой организации', 'По всем актам организаций',
            'Покажи акты выбранного проекта «По всем актам организации»'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
        }
    }

    public function test_report_binding_removes_selected_project_ids_and_keeps_other_filters_and_nullness(): void
    {
        $question = 'По всем актам организации. Не ограничивайся выбранным проектом.';
        $filters = [['field' => 'project_id', 'operator' => 'eq', 'value' => 52],
            ['field' => 'date', 'operator' => 'gte', 'value' => '2026-10-01'],
            ['field' => 'status', 'operator' => 'neq', 'value' => 'rejected'],
            ['field' => 'project_id', 'operator' => 'is_null', 'value' => null]];
        $arguments = ['title' => 'Акты', 'format' => 'csv', 'sections' => [
            ['project_id' => 52, 'filters' => $filters, 'columns' => ['status', 'currency']],
            ['project_id' => 100, 'filters' => [['field' => 'project_id', 'operator' => 'in', 'value' => ['52', '100']]]],
            ['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'in', 'value' => ['52']]]],
        ]];
        $expected = $arguments;
        $expected['sections'][0]['project_id'] = null;
        $expected['sections'][0]['filters'] = array_slice($filters, 1);
        $expected['sections'][2] = ['project_id' => null, 'filters' => []];
        self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan('Покажи акты'))->bindReportScope('Покажи акты', $arguments));
        $invalid = ['sections' => 'invalid'];
        self::assertSame($invalid, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $invalid));
    }

    public function test_report_binding_preserves_trusted_named_scope_before_organization_words(): void
    {
        $question = 'По всей организации';
        $plan = $this->plan($question);
        $plan['named_project_scope'] = ['project_id' => 100, 'message_sha256' => hash('sha256', $question)];
        $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 52]]]]];
        $expected = $arguments;
        $expected['sections'][0]['project_id'] = 100;
        self::assertSame($expected, AssistantRequestProjectScope::fromPlan($plan)->bindReportScope($question, $arguments));
        $plan['named_project_scope']['message_sha256'] = str_repeat('0', 64);
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($plan)->bindReportScope($question, $arguments));
    }

    public function test_record_scope_respects_affirmative_current_project_constraints(): void
    {
        foreach (['Отчёт по всем актам организации в выбранном проекте',
            'Сводка всех журналов нашей организации, только текущего проекта',
            'По всей организации, только в выбранном проекте'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertTrue($scope->requested);
            self::assertSame(52, $scope->projectId);
        }
        foreach (['По всем актам организации. Не ограничивайся выбранным проектом.',
            'По всем актам организации, не только выбранного проекта.',
            'По всем актам организации, без ограничения выбранным проектом.'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertFalse(AssistantRequestProjectScope::fromPlan($this->plan($question))->requested);
        }
    }

    public function test_organization_report_keeps_project_exclusions_and_comparison_sections(): void
    {
        $question = 'Нужны все акты всей организации, кроме проекта 52';
        $arguments = ['sections' => [['project_id' => null, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]]]];
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        $question = 'Сводка по всем актам организации. Сравни проекты 52 и 100 по отдельности.';
        $arguments = ['sections' => [['project_id' => 52, 'filters' => []], ['project_id' => 100, 'filters' => []]]];
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
    }

    public function test_genitive_all_projects_scope_clears_selected_project_and_preserves_negation(): void
    {
        $question = 'Сделай Excel по просроченным задачам всех проектов организации';
        self::assertTrue(AssistantRequestProjectScope::organizationWide($question));
        self::assertNull(AssistantRequestProjectScope::fromPlan($this->plan($question))->projectId);
        self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true));
        self::assertFalse(AssistantRequestProjectScope::organizationWide('Не всех проектов организации, только нашего объекта'));
    }

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

    public function test_organization_search_clears_model_project_filter_and_keeps_other_arguments(): void
    {
        foreach (['По всей нашей организации', 'По всем нашим проектам', 'Во всех наших объектах', 'В целом по нашей организации'] as $question) {
            $arguments = ['domain' => 'tenders', 'project_id' => 52, 'limit' => 20, 'query' => ''];
            self::assertNull(AssistantRequestProjectScope::fromPlan($this->plan($question))->projectId);
            self::assertSame(array_replace($arguments, ['project_id' => null]),
                AssistantRequestProjectScope::bindSearchScope($question, $arguments, 52, true));
        }
        self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope('Проект СевГУ', ['project_id' => 100], 52, true));
        self::assertSame(['project_id' => 52], AssistantRequestProjectScope::bindSearchScope('Наш объект', [], 52, true));
        self::assertSame([], AssistantRequestProjectScope::bindSearchScope('Сотрудники', [], 52, false));
    }

    private function plan(string $question): array
    {
        return ['request' => ['message' => $question, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }
}
