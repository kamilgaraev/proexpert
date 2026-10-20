<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use PHPUnit\Framework\TestCase;

final class AssistantRequestProjectScopeTest extends TestCase
{
    public function test_work_organization_phrases_do_not_expand_the_selected_project(): void
    {
        $questions = ['Покажи все задачи организации работ', 'Все задачи организации строительства',
            'По всем задачам организации строительных работ', 'Покажи все просроченные задачи организации работ'];
        self::assertSame(array_fill(0, count($questions), false), array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            self::assertSame(['project_id' => 52], AssistantRequestProjectScope::bindSearchScope($question, [], 52, true), $question);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => []]]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments), $question);
        }
    }

    public function test_estimate_child_entities_use_the_organization_scope(): void
    {
        $questions = ['Все позиции смет организации', 'По всем позициям смет нашей организации',
            'Сводка всех ресурсов организации', 'Все ресурсы смет организации', 'Все разделы смет организации'];
        self::assertSame(array_fill(0, count($questions), true), array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
            self::assertSame(['sections' => [['project_id' => null, 'filters' => []]]],
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [['project_id' => 52, 'filters' => []]]]), $question);
        }
    }

    public function test_record_definitions_keep_organization_scope_and_report_filters(): void
    {
        $questions = ['Все просроченные задачи организации', 'По всем действующим договорам организации',
            'Все утверждённые акты нашей организации', 'Все частично оплаченные договоры организации',
            'Все активные незавершённые задачи организации', 'По всем нашим действующим договорам организации',
            'Все не подписанные договоры организации', 'Все доступные позиции смет организации'];
        self::assertSame(array_fill(0, count($questions), true), array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
            $filters = [['field' => 'status', 'operator' => 'eq', 'value' => 'active']];
            self::assertSame(['sections' => [['project_id' => null, 'filters' => $filters]]],
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [['project_id' => 52, 'filters' => $filters]]]), $question);
        }
    }

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
            ['project_id' => null, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 100]]],
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
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'filters' => []],
            ['scope' => 'project', 'project_id' => 52, 'filters' => []], ['scope' => 'project', 'project_id' => 100, 'filters' => []]]];
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
    }

    public function test_negated_current_project_prepositions_do_not_force_the_excluded_project(): void
    {
        foreach (['в выбранном проекте', 'на текущем объекте', 'по выбранному проекту', 'для текущего проекта',
            'из выбранного проекта', 'с этого объекта'] as $clause) {
            $question = 'По всем актам организации, не '.$clause;
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $clause);
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertFalse($scope->requested);
            self::assertNull($scope->projectId);
            $arguments = ['sections' => [['project_id' => null, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]]]];
            self::assertSame($arguments, $scope->bindReportScope($question, $arguments));
        }
    }

    public function test_organization_record_scope_does_not_match_process_or_requirement_wording(): void
    {
        foreach (['Покажи задачи по всем этапам организации работ', 'Покажи акты по всем требованиям организации',
            'Отчёт по всем вопросам организации работ', 'Покажи договоры по всем правилам организации'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => 52], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true));
            $arguments = ['sections' => [['project_id' => 52, 'filters' => []]]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        }
    }

    public function test_direct_current_project_exclusions_keep_the_organization_scope(): void
    {
        $questions = array_map(static fn (string $clause): string => 'По всем актам организации '.$clause,
            ['без выбранного проекта', 'за исключением текущего проекта', 'кроме этого объекта', 'исключая данный проект']);
        self::assertSame([true, true, true, true], array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertFalse($scope->requested, $question);
            self::assertNull($scope->projectId);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]]]];
            $expected = $arguments;
            $expected['sections'][0]['project_id'] = null;
            self::assertSame($expected, $scope->bindReportScope($question, $arguments));
        }
    }

    public function test_unquoted_project_comparison_keeps_its_explicit_section(): void
    {
        foreach (['Сравни отчёт по всем актам организации с проектом СевГУ',
            'Сопоставь сводку всех актов организации и проекта Учебный ПТО',
            'Сравнение всех актов организации с объектом Ремонт офиса'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'filters' => []], ['scope' => 'project', 'project_id' => 52, 'filters' => []]]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
                static fn (array $arguments): array => ['status' => 'success', 'results' => [['id' => 52]], 'result_window' => ['has_more' => false]]), $question);
        }
        foreach (['Сравни отчёт по всем актам организации за сентябрь и октябрь',
            'Сопоставь акты всей организации с графиком', 'Сравни итоги всех актов организации и сроки проекта'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => []]]];
            self::assertSame(['sections' => [['project_id' => null, 'filters' => []]]],
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments), $question);
        }
    }

    public function test_nominative_organization_records_clear_the_selected_project(): void
    {
        $questions = ['Сделай CSV: все акты организации по статусам', 'Покажи все наши договоры организации',
            'Все сотрудники нашей организации', 'Все журналы организации'];
        self::assertSame([true, true, true, true], array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true));
            self::assertSame(['sections' => [['project_id' => null, 'filters' => []]]],
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                    ['sections' => [['project_id' => 52, 'filters' => []]]]));
        }
    }

    public function test_organization_and_current_project_comparison_keeps_both_scopes(): void
    {
        $question = 'Сравни отчёт по всем актам организации с выбранным проектом';
        self::assertTrue(AssistantRequestProjectScope::organizationWide($question));
        $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
        self::assertFalse($scope->requested);
        $arguments = ['sections' => [['title' => 'По всей организации', 'scope' => 'organization', 'project_id' => null, 'filters' => []],
            ['title' => 'Выбранный проект', 'scope' => 'project', 'project_id' => 52, 'filters' => []]]];
        self::assertSame($arguments, $scope->bindReportScope($question, $arguments));
    }

    public function test_explicit_comparison_clears_only_the_stale_organization_section(): void
    {
        $question = 'Сравни отчёт по всем актам организации с проектом 100';
        $arguments = ['sections' => [['title' => 'По всей организации', 'scope' => 'organization', 'project_id' => 52,
            'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 52]]],
            ['title' => 'Проект 100', 'scope' => 'project', 'project_id' => 100, 'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 100]]]]];
        $expected = $arguments;
        $expected['sections'][0]['project_id'] = null;
        $expected['sections'][0]['filters'] = [];
        self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        $arguments['sections'][1]['filters'][] = ['field' => 'project_id', 'operator' => 'in', 'value' => [52, 100]];
        $expected['sections'][1]['filters'] = $arguments['sections'][1]['filters'];
        self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
    }

    public function test_mixed_scope_requires_section_metadata_and_does_not_infer_it_from_titles(): void
    {
        $question = 'Сравни отчёт по всем актам организации с выбранным проектом';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
            ['sections' => [['title' => 'По всей организации', 'project_id' => 52], ['title' => 'Выбранный проект', 'project_id' => 52]]]);
    }

    public function test_mixed_named_scope_uses_only_a_complete_unique_exact_search(): void
    {
        $question = 'Сравни все акты организации с проектом «СевГУ»';
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => 52, 'filters' => []],
            ['scope' => 'project', 'project_id' => null, 'filters' => []]]];
        $expected = $arguments;
        $expected['sections'][0]['project_id'] = null;
        $expected['sections'][1]['project_id'] = 100;
        $calls = [];
        self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
            static function (array $arguments) use (&$calls): array {
                $calls[] = $arguments;

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            }));
        self::assertSame([['query' => 'СевГУ', 'name_match' => 'exact', 'limit' => 2]], $calls);
    }

    public function test_mixed_scope_rejects_ambiguous_or_unavailable_names_and_conflicting_ids(): void
    {
        $named = 'Сравни все акты организации с проектом СевГУ';
        $sections = [['scope' => 'organization', 'project_id' => null], ['scope' => 'project', 'project_id' => 52]];
        foreach ([null, ['status' => 'error'], ['status' => 'success', 'results' => [], 'result_window' => ['has_more' => false]],
            ['status' => 'success', 'results' => [['id' => 52]], 'result_window' => ['has_more' => true]],
            ['status' => 'success', 'results' => [['id' => 52], ['id' => 100]], 'result_window' => ['has_more' => false]]] as $result) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($named))->bindReportScope($named, ['sections' => $sections], static fn (): mixed => $result);
                self::fail('Unproven named scope was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
        $question = 'Сравни все акты организации с проектом 100';
        foreach ([['scope' => 'organization', 'project_id' => 100], ['scope' => 'project', 'project_id' => 200],
            ['scope' => 'project', 'project_id' => 100, 'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 52]]]] as $section) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [$section]]);
                self::fail('Conflicting section scope was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_current_or_literal_project_does_not_bypass_a_named_comparison_lookup(): void
    {
        foreach (['выбранным проектом', 'проектом 52'] as $first) {
            $question = 'Сравни все акты организации с '.$first.' и проектом СевГУ';
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null],
                ['scope' => 'project', 'project_id' => 52], ['scope' => 'project', 'project_id' => 100]]];
            $calls = [];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
                static function (array $arguments) use (&$calls): array {
                    $calls[] = $arguments;

                    return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
                }));
            self::assertSame([['query' => 'СевГУ', 'name_match' => 'exact', 'limit' => 2]], $calls);
        }
    }

    public function test_mixed_report_requires_every_explicitly_compared_project(): void
    {
        $question = 'Сравни все акты организации с проектами 52 и 100';
        $rejected = [];
        foreach ([[52], [52, 52], [], [52, 52, 100]] as $ids) {
            $sections = [['scope' => 'organization', 'project_id' => null]];
            foreach ($ids as $id) {
                $sections[] = ['scope' => 'project', 'project_id' => $id];
            }
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                $rejected[] = false;
            } catch (AssistantReportScopeAmbiguous) {
                $rejected[] = true;
            }
        }
        self::assertSame([true, true, true, true], $rejected);
        $sections = [['scope' => 'organization', 'project_id' => null], ['scope' => 'project', 'project_id' => 52], ['scope' => 'project', 'project_id' => 100]];
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        $sections[] = ['scope' => 'project', 'project_id' => 52, 'domain' => 'contracts', 'entity_type' => 'contract'];
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
    }

    public function test_explicit_organization_scope_rejects_positive_project_filters(): void
    {
        $question = 'Сравни все акты организации с проектом 100';
        $rejected = [];
        foreach ([['eq', 100], ['in', [100]], ['in', [52, 100]], ['gt', 52], ['contains', '100']] as [$operator, $value]) {
            $sections = [['scope' => 'organization', 'project_id' => null,
                'filters' => [['field' => 'project_id', 'operator' => $operator, 'value' => $value]]],
                ['scope' => 'project', 'project_id' => 100]];
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                $rejected[] = false;
            } catch (AssistantReportScopeAmbiguous) {
                $rejected[] = true;
            }
        }
        self::assertSame([true, true, true, true, true], $rejected);
        foreach (['neq', 'is_null', 'is_not_null'] as $operator) {
            $sections = [['scope' => 'organization', 'project_id' => null,
                'filters' => [['field' => 'project_id', 'operator' => $operator, 'value' => 52]]], ['scope' => 'project', 'project_id' => 100]];
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        }
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

    public function test_organization_scope_without_section_metadata_rejects_an_unrequested_project(): void
    {
        $question = 'Сделай CSV: все акты организации';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
            ['sections' => [['project_id' => 100, 'filters' => []]]]);
    }

    public function test_supported_organization_records_clear_the_selected_project(): void
    {
        foreach (['дефекты', 'замечания', 'несоответствия', 'инциденты', 'нарушения', 'инструктажи',
            'наряды', 'табели', 'трудозатраты', 'сделки', 'лиды', 'компании', 'контакты', 'обращения',
            'коммерческие предложения', 'статьи базы знаний', 'остатки', 'движения материалов',
            'резервы', 'ячейки хранения', 'графики', 'комплекты документации', 'простои', 'сменные отчёты',
            'начисления', 'чек-листы', 'проверки', 'претензии'] as $records) {
            $question = 'Покажи все '.$records.' организации';
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true));
        }
    }

    public function test_organization_scope_without_section_metadata_rejects_unrequested_project_filters(): void
    {
        $question = 'Сделай CSV: все акты организации';
        $rejected = [];
        foreach ([['eq', 100], ['in', [52, 100]], ['gt', 52], ['contains', '100']] as [$operator, $value]) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                    ['sections' => [['project_id' => null, 'filters' => [['field' => 'project_id', 'operator' => $operator, 'value' => $value]]]]]);
                $rejected[] = false;
            } catch (AssistantReportScopeAmbiguous) {
                $rejected[] = true;
            }
        }
        self::assertSame([true, true, true, true], $rejected);
    }

    public function test_unquoted_comparison_trims_only_a_complete_period_or_grouping_after_a_proven_empty_full_name(): void
    {
        foreach (['за октябрь', 'за октябрь 2026 года', 'по статусам', 'за октябрь по статусам и валютам', 'с группировкой по статусам',
            'по состоянию на вчера', 'с начала 2026 года', 'с 2026-10-01 по 2026-10-15', 'за 01.10.2026'] as $suffix) {
            $question = 'Сравни все акты организации с проектом СевГУ '.$suffix;
            $sections = [['scope' => 'organization', 'project_id' => null], ['scope' => 'project', 'project_id' => 100]];
            $calls = [];
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                ['sections' => $sections], static function (array $arguments) use (&$calls): array {
                    $calls[] = $arguments;

                    return ['status' => 'success', 'results' => $arguments['query'] === 'СевГУ' ? [['id' => 100]] : [], 'result_window' => ['has_more' => false]];
                }));
            self::assertSame('СевГУ '.$suffix, $calls[0]['query']);
            self::assertSame('СевГУ', $calls[array_key_last($calls)]['query']);
            foreach ($calls as $call) {
                self::assertSame(['query' => $call['query'], 'name_match' => 'exact', 'limit' => 2], $call);
            }
        }
    }

    public function test_full_literal_comparison_names_keep_priority_and_unsafe_tails_do_not_fall_back(): void
    {
        foreach (['СевГУ за октябрь', '«СевГУ за октябрь»', 'СевГУ по статусам', '«Без проекта 52»'] as $name) {
            $question = 'Сравни все акты организации с проектом '.$name;
            $calls = [];
            $sections = [['scope' => 'organization', 'project_id' => null], ['scope' => 'project', 'project_id' => 100]];
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                ['sections' => $sections], static function (array $arguments) use (&$calls): array {
                    $calls[] = $arguments;

                    return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
                }));
            self::assertCount(1, $calls);
        }
        foreach (['«СевГУ за октябрь»', 'СевГУ за октябрь с корпусом Б', 'СевГУ по статусам корпуса Б',
            'СевГУ по состоянию на', 'СевГУ с начала', 'СевГУ с 1 по', 'СевГУ за 12.10'] as $name) {
            $question = 'Сравни все акты организации с проектом '.$name;
            $calls = [];
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                    ['sections' => [['scope' => 'project', 'project_id' => 100]]], static function (array $arguments) use (&$calls): array {
                        $calls[] = $arguments;

                        return ['status' => 'success', 'results' => [], 'result_window' => ['has_more' => false]];
                    });
                self::fail('An unproven name was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertCount(1, $calls, $name);
            }
        }
        foreach ([null, ['status' => 'error'], ['status' => 'success', 'results' => [], 'result_window' => ['has_more' => true]],
            ['status' => 'success', 'results' => [['id' => 100], ['id' => 200]], 'result_window' => ['has_more' => false]]] as $result) {
            $question = 'Сравни все акты организации с проектом СевГУ за октябрь';
            $calls = 0;
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                    ['sections' => [['scope' => 'project', 'project_id' => 100]]], static function () use (&$calls, $result): mixed {
                        $calls++;

                        return $result;
                    });
                self::fail('An incomplete lookup was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertSame(1, $calls);
            }
        }
    }

    public function test_project_exclusions_do_not_hide_an_affirmative_comparison_or_become_positive_bindings(): void
    {
        foreach (['без проекта 52', 'кроме проектов 52 и 53', 'исключая выбранный проект',
            'за исключением проекта «Север с корпусом Б»', 'без проекта Север с корпусом Б'] as $exclusion) {
            $question = 'Сравни все акты организации '.$exclusion.' с проектом 100';
            $sections = [['scope' => 'organization', 'project_id' => null, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]],
                ['scope' => 'project', 'project_id' => 100]];
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                ['sections' => $sections], static function (): never {
                    self::fail('Excluded names must not be looked up');
                }), $question);
        }
    }

    public function test_negated_record_filters_do_not_negate_the_organization_scope(): void
    {
        foreach (['Не включай черновики в отчет по всем актам организации',
            'Не показывай отклонённые акты в отчёте по всей организации',
            'Не добавляй черновики в отчёт по всем проектам',
            'Не учитывай отменённые договоры в сводке всех договоров организации'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true));
            $sections = [['project_id' => 52, 'filters' => [['field' => 'status', 'operator' => 'neq', 'value' => 'draft']]]];
            $expected = $sections;
            $expected[0]['project_id'] = null;
            self::assertSame(['sections' => $expected], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        }
        foreach (['Не всех актов организации', 'Не по всей организации', 'Не показывай все акты организации',
            'Кроме всех журналов организации', 'Исключая все проекты', 'Не ищи по всем проектам',
            'Не надо искать по всем проектам', 'Не работай по всем проектам', 'Не выполняй поиск по всем проектам'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
        }
    }

    public function test_mixed_search_preserves_each_explicit_project_side_and_the_organization_side(): void
    {
        foreach (['Сравни просроченные задачи выбранного проекта и во всех проектах' => 52,
            'Сравни все акты организации с выбранным проектом' => 52,
            'Сравни все акты организации с проектом 100' => 100,
            'Сравни выбранный проект со всей организацией' => 52] as $question => $id) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, [], 52, true));
            self::assertSame(['project_id' => $id], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => $id], 52, true));
        }
    }

    public function test_mixed_named_search_rechecks_the_exact_name_and_rejects_unproven_ids(): void
    {
        $question = 'Сравни все акты организации с проектом СевГУ за октябрь';
        $calls = [];
        self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 100], 52, true,
            static function (array $arguments) use (&$calls): array {
                $calls[] = $arguments;

                return ['status' => 'success', 'results' => $arguments['query'] === 'СевГУ' ? [['id' => 100]] : [], 'result_window' => ['has_more' => false]];
            }));
        self::assertSame([['query' => 'СевГУ за октябрь', 'name_match' => 'exact', 'limit' => 2], ['query' => 'СевГУ', 'name_match' => 'exact', 'limit' => 2]], $calls);
        foreach ([['project_id' => 52], ['project_id' => 200]] as $arguments) {
            try {
                AssistantRequestProjectScope::bindSearchScope('Сравни все акты организации с проектом 100', $arguments, 52, true);
                self::fail('An unrequested search project was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 100], 52, true, static fn (): array => ['status' => 'error']);
    }

    public function test_mixed_report_requires_a_real_organization_section_as_well_as_project_coverage(): void
    {
        $question = 'Сравни все акты организации с проектом 100';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
            ['sections' => [['scope' => 'project', 'project_id' => 100]]]);
    }

    private function plan(string $question): array
    {
        return ['request' => ['message' => $question, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }
}
