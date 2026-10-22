<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantReportScopeAmbiguous;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComparisonSpecification;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportEntityQuery;
use PHPUnit\Framework\TestCase;

final class AssistantRequestProjectScopeTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_supported_file_formats_preserve_comparison_scopes_and_search_requirements(): void
    {
        $comparison = 'Сравни все акты организации с актами проекта 100';
        $expectedCoverage = AssistantRequestProjectScope::fromPlan($this->plan($comparison))->searchCoverageRequirements($comparison);
        foreach (['PDF', 'pdf', 'pDf', 'XLSX', 'CSV', 'Excel'] as $format) {
            $question = 'Сделай '.$format.': сравни все акты организации с актами проекта 100';
            $arguments = ['format' => strtolower($format) === 'excel' ? 'xlsx' : strtolower($format), 'sections' => [
                ['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []],
            ]];
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertSame($arguments, $scope->bindReportScope($question, $arguments), $question);
            self::assertSame($expectedCoverage, $scope->searchCoverageRequirements($question), $question);
        }
    }

    public function test_comparison_cannot_publish_equal_filters_for_different_requested_periods(): void
    {
        $filters = [['field' => 'date', 'operator' => 'gte', 'value' => '2025-01-01'],
            ['field' => 'date', 'operator' => 'lte', 'value' => '2025-12-31']];
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => $filters],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => $filters]];
        foreach (['Сравни все акты организации за 2025 год с актами проекта 100 за 2026 год',
            'Сравни акты организации за 2025 год с актами проекта 100 за 2026 год'] as $question) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                self::fail('Different requested periods accepted: '.$question);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unbound_comparison_conditions_require_clarification_before_search_coverage(): void
    {
        foreach (['Сравни все акты организации дороже 1000 рублей с актами проекта 100 дешевле 2000 рублей',
            'Сравни акты организации за 2025 год с актами проекта 100 за 2026 год',
            'Сравни все акты организации за прошлый месяц с актами проекта 100 за этот месяц',
            'Сравни все акты организации в рублях с актами проекта 100 в долларах'] as $question) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
                self::fail('Unbound comparison condition was ignored: '.$question);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unquantified_comparison_guard_preserves_confirmed_literal_project_names(): void
    {
        $name = 'Акты организации за 2025 год';
        foreach ([$name, '«'.$name.'»'] as $operand) {
            $question = 'Сравни акты проекта '.$operand;
            $search = static fn (array $arguments): array => ['status' => 'success',
                'results' => ($arguments['query'] ?? null) === $name ? [['id' => 77]] : [],
                'result_window' => ['has_more' => false]];
            $sections = [['scope' => 'project', 'project_id' => 77, 'domain' => 'acts', 'entity_type' => 'performance_act']];
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertSame(['sections' => $sections], $scope->bindReportScope($question, ['sections' => $sections], $search));
            self::assertNull($scope->searchCoverageRequirements($question, $search));
        }
    }

    public function test_comparison_without_requested_conditions_cannot_invent_equal_filters(): void
    {
        $question = 'Сравни все акты организации с актами проекта 100';
        foreach ([['field' => 'status', 'operator' => 'neq', 'value' => 'rejected'],
            ['field' => 'currency', 'operator' => 'eq', 'value' => 'RUB'],
            ['field' => 'date', 'operator' => 'gte', 'value' => '2025-01-01']] as $filter) {
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => [$filter]],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => [$filter]]];
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                self::fail('Invented identical comparison filters were accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_comparison_grouping_is_metadata_and_does_not_hide_a_filter(): void
    {
        $question = 'Сравни все акты организации с актами проекта 100 по статусам и валютам';
        $section = ['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'columns' => ['status', 'currency'], 'group_by' => ['status', 'currency']];
        $sections = [$section, array_replace($section, ['scope' => 'project', 'project_id' => 100])];
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        $question .= ' за 2025 год';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
    }

    public function test_section_modifiers_preserve_organization_and_project_search_scopes(): void
    {
        foreach (['Покажи все акты организации и отдельно договоры по проекту 100' => 100,
            'Покажи все акты организации, а также отдельно договоры проекта 100' => 100,
            'Покажи все акты организации и также договоры проекта 100' => 100,
            'Покажи все акты организации и отдельно также договоры выбранного проекта' => 52] as $question => $id) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => $id, 'domain' => 'contracts', 'entity_type' => 'contract']];
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertSame(['sections' => $sections], $scope->bindReportScope($question, ['sections' => $sections]), $question);
            self::assertSame(null, AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true)['project_id']);
            self::assertSame($id, AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => $id], 52, true)['project_id']);
            self::assertEqualsCanonicalizing(['["organization","acts","performance_act"]', '["project:'.$id.'","contracts","contract"]'],
                array_keys($scope->searchCoverageRequirements($question)['subjects']));
        }
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 777], 52, true);
    }

    public function test_section_modifiers_inside_confirmed_project_names_remain_literal(): void
    {
        foreach (['Отдельно договоры по проекту 100', 'Также договоры по проекту 100'] as $name) {
            $question = 'Покажи все акты организации и отдельно договоры проекта «'.$name.'»';
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 77, 'domain' => 'contracts', 'entity_type' => 'contract']];
            $search = static function (array $arguments) use ($name): array {
                self::assertSame($name, $arguments['query']);

                return ['status' => 'success', 'results' => [['id' => 77]], 'result_window' => ['has_more' => false]];
            };
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search));
        }
    }

    public function test_recognized_comparison_commands_preserve_explicit_status_conditions(): void
    {
        $commands = ['Найди различия между', 'Найдите основные различия между', 'Выяви разницу между',
            'Определи ключевые различия между', 'Объясни разницу между', 'Укажи существенные различия между',
            'В чём главные различия между', 'Проведи сверку между', 'Выполни ключевую сверку между',
            'Провести сверку между', 'Выполнить сверку между', 'Нужна сверка между', 'Требуется сверка между',
            'Для сверки между', 'Сделать сверку между', 'Сформировать сверку между', 'Подготовить сверку между'];
        foreach ($commands as $command) {
            $question = $command.' всеми утверждёнными актами организации и черновыми актами проекта 100';
            self::assertTrue(AssistantReportComparisonSpecification::requestsComparison($question), $question);
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                    'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'draft']]]];
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertSame(['sections' => $sections], $scope->bindReportScope($question, ['sections' => $sections]), $question);
            self::assertCount(2, $scope->searchCoverageRequirements($question)['subjects']);
            $sections[1]['filters'][0]['value'] = 'approved';
            try {
                $scope->bindReportScope($question, ['sections' => $sections]);
                self::fail('Requested draft condition ignored: '.$question);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_comparison_commands_inside_confirmed_project_names_remain_literal(): void
    {
        foreach (['Найди основные различия', 'Проведи ключевую сверку'] as $name) {
            $question = 'Сравни все утверждённые акты организации с черновыми актами проекта «'.$name.'»';
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                    'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'draft']]]];
            $search = static function (array $arguments) use ($name): array {
                self::assertSame($name, $arguments['query']);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search));
        }
        $question = 'Найди различия между всеми утверждёнными актами организации и черновыми актами проекта 100 дороже 1000 рублей';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
    }

    public function test_complete_organization_qualifiers_keep_independent_source_bindings(): void
    {
        foreach (['акты всей организации', 'акты всей нашей организации', 'акты в целом по организации',
            'акты в целом по нашей организации', 'все акты организации', 'все акты нашей организации'] as $phrase) {
            foreach (['Покажи '.$phrase.', а также договоры проекта 100',
                'Покажи договоры проекта 100, а также '.$phrase] as $question) {
                self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
                $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
                $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                    ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']];
                self::assertSame(['sections' => $sections], $scope->bindReportScope($question, ['sections' => $sections]), $question);
                self::assertEqualsCanonicalizing(['["organization","acts","performance_act"]', '["project:100","contracts","contract"]'],
                    array_keys($scope->searchCoverageRequirements($question)['subjects']), $question);
            }
        }
    }

    public function test_organization_qualifiers_inside_confirmed_project_names_remain_literal(): void
    {
        foreach (['Акты всей организации', 'В целом по нашей организации'] as $name) {
            $question = 'Покажи акты всей организации, а также договоры проекта «'.$name.'»';
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']];
            $search = static function (array $arguments) use ($name): array {
                self::assertSame($name, $arguments['query']);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search));
        }
        $question = 'Покажи акты всей организации за прошлый месяц, а также договоры проекта 100';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
    }

    public function test_named_exclusion_preserves_a_following_independent_section_after_complete_empty_full_name(): void
    {
        $question = 'Все акты организации без проекта Альфа и договоры проекта 100';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']];
        $calls = [];
        $search = static function (array $arguments) use (&$calls): array {
            $calls[] = $arguments['query'];

            return ['status' => 'success', 'results' => $arguments['query'] === 'Альфа' ? [['id' => 52]] : [], 'result_window' => ['has_more' => false]];
        };
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search));
        self::assertSame(['Альфа и договоры проекта 100', 'Альфа'], $calls);
    }

    public function test_full_literal_excluded_project_name_precedes_a_possible_new_section(): void
    {
        $question = 'Все акты организации без проекта Альфа и договоры проекта 100';
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 77]]]]];
        $calls = [];
        $search = static function (array $arguments) use (&$calls): array {
            $calls[] = $arguments['query'];
            self::assertSame('Альфа и договоры проекта 100', $arguments['query']);

            return ['status' => 'success', 'results' => [['id' => 77]], 'result_window' => ['has_more' => false]];
        };
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search));
        self::assertSame(['Альфа и договоры проекта 100'], $calls);
    }

    public function test_incomplete_or_failed_full_exclusion_lookup_never_falls_back_to_a_shorter_name(): void
    {
        $question = 'Все акты организации без проекта Альфа и договоры проекта 100';
        foreach ([['status' => 'error'],
            ['status' => 'success', 'results' => [], 'result_window' => ['has_more' => true]],
            ['status' => 'success', 'results' => []],
            ['status' => 'success', 'results' => [['id' => 52], ['id' => 77]], 'result_window' => ['has_more' => false]]] as $result) {
            $calls = [];
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => []],
                    static function (array $arguments) use (&$calls, $result): array {
                        $calls[] = $arguments['query'];

                        return $result;
                    });
                self::fail('Unproven complete empty result allowed a shorter project name');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertSame(['Альфа и договоры проекта 100'], $calls);
            }
        }
    }

    public function test_longest_existing_excluded_name_precedes_an_earlier_possible_section_boundary(): void
    {
        $question = 'Все акты организации без проекта Альфа и договоры проекта 100 и договоры проекта 200';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 77]]],
            ['scope' => 'project', 'project_id' => 200, 'domain' => 'contracts', 'entity_type' => 'contract']];
        $calls = [];
        $search = static function (array $arguments) use (&$calls): array {
            $calls[] = $arguments['query'];

            return ['status' => 'success', 'results' => $arguments['query'] === 'Альфа и договоры проекта 100' ? [['id' => 77]] : [],
                'result_window' => ['has_more' => false]];
        };
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search));
        self::assertSame(['Альфа и договоры проекта 100 и договоры проекта 200', 'Альфа и договоры проекта 100'], $calls);
    }

    public function test_quoted_excluded_name_keeps_internal_section_words_literal(): void
    {
        $question = 'Все акты организации без проекта «Альфа и договоры проекта 100» и договоры проекта 200';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 77]]],
            ['scope' => 'project', 'project_id' => 200, 'domain' => 'contracts', 'entity_type' => 'contract']];
        $search = static function (array $arguments): array {
            self::assertSame('Альфа и договоры проекта 100', $arguments['query']);

            return ['status' => 'success', 'results' => [['id' => 77]], 'result_window' => ['has_more' => false]];
        };
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search));
    }

    public function test_comparison_cannot_replace_explicitly_different_requested_status_conditions_with_equal_filters(): void
    {
        $question = 'Сравни все утверждённые акты организации с черновыми актами проекта 100';
        self::assertTrue(AssistantRequestProjectScope::organizationWide($question));
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]]];
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
    }

    public function test_comparison_preserves_each_explicit_requested_act_status_condition(): void
    {
        $question = 'Сравни все утверждённые акты организации с черновыми актами проекта 100';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'draft']]]];
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        $sections[1]['columns'] = ['id'];
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
    }

    public function test_comparison_status_words_in_a_confirmed_project_name_do_not_add_conditions(): void
    {
        $question = 'Сравни все акты организации с проектом «Утверждённые акты»';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']];
        $search = static fn (): array => ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search));
    }

    public function test_independent_act_status_conditions_cannot_be_swapped_between_scopes(): void
    {
        $question = 'Все утверждённые акты организации, а также черновые акты проекта 100';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'draft']]]];
        self::assertTrue(AssistantRequestProjectScope::organizationWide($question));
        $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
        self::assertSame(['sections' => $sections], $scope->bindReportScope($question, ['sections' => $sections]));
        $sections[0]['filters'][0]['value'] = 'draft';
        $sections[1]['filters'][0]['value'] = 'approved';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        $scope->bindReportScope($question, ['sections' => $sections]);
    }

    public function test_unsupported_conditions_in_independent_clauses_require_clarification(): void
    {
        foreach (['Все акты организации за прошлый месяц, а также акты проекта 100',
            'Все акты организации, а также акты проекта 100. Название «АВР-100».',
            'Все акты организации, а также акты проекта 100 дороже 1000 рублей',
            'Все акты организации, а также акты проекта 100. Только за вчера.',
            'Все акты организации с полями номер за прошлый месяц, а также акты проекта 100'] as $question) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
                self::fail('Unsupported condition was ignored: '.$question);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_independent_act_conditions_preserve_nouns_ellipsis_and_each_filter_set(): void
    {
        foreach (['Все утверждённые акты организации, а также черновики актов проекта 100',
            'Все утвержденные акты организации, а также черновые акты проекта 100'] as $question) {
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                    'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'draft']]]];
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        }
        $question = 'Все утверждённые акты организации и проекта 100';
        $sections[1]['filters'][0]['value'] = 'approved';
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        $question = 'Все утверждённые акты организации, черновые акты организации, а также подписанные акты проекта 100';
        $sections[1]['filters'][0]['value'] = 'signed';
        $drafts = array_replace($sections[0], ['filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'draft']]]);
        $all = ['sections' => [$sections[1], $drafts, $sections[0]]];
        self::assertSame($all, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $all));
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
    }

    public function test_unrequested_filters_cannot_narrow_an_independent_section(): void
    {
        $question = 'Все акты организации, а также акты проекта 100';
        $sections = [['scope' => 'organization', 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]]];
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
    }

    public function test_independent_subject_bindings_preserve_multiple_operands_and_literal_names(): void
    {
        foreach (['Все акты организации, а также по проекту 100 — договоры' => [100],
            'Покажи все акты организации вместе с договорами проекта 100' => [100],
            'Договоры выбранного проекта вместе со всеми актами организации' => [52],
            'Расположи все акты организации сверху, а договоры проекта 100 снизу' => [100],
            'Договоры выбранного проекта, а также все акты организации' => [52],
            'Все акты организации, а также договоры проектов 100 и 52' => [100, 52],
            'Все акты организации, а также договоры проекта «Акты организации, и проект 777»' => [100],
            'Все акты организации, а также договоры проекта 100 и проекта «100»' => [100, 102],
            'Все акты организации, а также договоры проекта Альфа и проект Бета' => [100]] as $question => $ids) {
            $calls = [];
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $requirements = AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question,
                static function (array $arguments) use (&$calls): array {
                    $calls[] = $arguments['query'];

                    return ['status' => 'success', 'results' => [['id' => $arguments['query'] === '100' ? 102 : 100]], 'result_window' => ['has_more' => false]];
                });
            self::assertEqualsCanonicalizing($ids, $requirements['project_ids']);
            self::assertFalse($requirements['pairwise']);
            $expected = ['["organization","acts","performance_act"]'];
            foreach ($ids as $id) {
                $expected[] = '["project:'.$id.'","contracts","contract"]';
            }
            self::assertEqualsCanonicalizing($expected, array_keys($requirements['subjects']), $question);
            if (str_contains($question, '777')) {
                self::assertSame(['Акты организации, и проект 777'], $calls);
            } elseif (str_contains($question, 'Альфа')) {
                self::assertSame(['Альфа и проект Бета'], $calls);
            } elseif (str_contains($question, '«100»')) {
                self::assertSame(['100'], $calls);
            } else {
                self::assertSame([], $calls);
            }
        }
    }

    public function test_independent_bindings_use_registered_entity_identity_before_organization_normalization(): void
    {
        $definition = new AssistantDomainDefinition('scope_test', '', 'scope_widget', [], [], [], [], '', entityTypes: ['scope_widget']);
        app()->instance(AssistantDomainCatalog::class, new AssistantDomainCatalog([...AssistantDomainCatalog::defaults(), $definition]));
        app('translator')->get('ai_assistant_facts.entities', [], 'ru');
        app('translator')->addLines(['ai_assistant_facts.entities.scope_widget' => 'Карточка проверки'], 'ru');
        $question = 'Все карточки проверки организации, а также договоры проекта 100';
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'scope_test', 'entity_type' => 'scope_widget'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']]];
        $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
        self::assertSame($arguments, $scope->bindReportScope($question, $arguments));
        $arguments['sections'][0]['domain'] = 'acts';
        $arguments['sections'][0]['entity_type'] = 'performance_act';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        $scope->bindReportScope($question, $arguments);
    }

    public function test_independent_bindings_reject_ambiguous_registered_entity_labels(): void
    {
        $definitions = [];
        app('translator')->get('ai_assistant_facts.entities', [], 'ru');
        foreach (['first', 'second'] as $type) {
            $definitions[] = new AssistantDomainDefinition('scope_'.$type, '', $type, [], [], [], [], '', entityTypes: [$type]);
            app('translator')->addLines(['ai_assistant_facts.entities.'.$type => 'Карточка проверки'], 'ru');
        }
        app()->instance(AssistantDomainCatalog::class, new AssistantDomainCatalog([...AssistantDomainCatalog::defaults(), ...$definitions]));
        $question = 'Все карточки проверки организации, а также договоры проекта 100';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
    }

    public function test_registered_subject_with_a_conjunction_is_one_literal_entity_phrase(): void
    {
        $definition = new AssistantDomainDefinition('scope_test', '', 'scope_card', [], [], [], [], '', entityTypes: ['scope_card']);
        app()->instance(AssistantDomainCatalog::class, new AssistantDomainCatalog([...AssistantDomainCatalog::defaults(), $definition]));
        app('translator')->get('ai_assistant_facts.entities', [], 'ru');
        app('translator')->addLines(['ai_assistant_facts.entities.scope_card' => 'Карточка качества и безопасности'], 'ru');
        $question = 'Все карточки качества и безопасности организации, а также договоры проекта 100';
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'scope_test', 'entity_type' => 'scope_card'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']]];
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
    }

    public function test_unknown_independent_subject_is_not_inferred_from_the_other_scope(): void
    {
        $question = 'Все акты организации, а также по проекту 100 — каракозябры';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
    }

    public function test_independent_sections_cannot_swap_requested_subjects_between_scopes(): void
    {
        $question = 'Покажи все акты организации, а также договоры проекта 100';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'contracts', 'entity_type' => 'contract'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']];
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
    }

    public function test_reconciliation_words_do_not_include_unrelated_sver_prefixes(): void
    {
        foreach (['сверху', 'сверление', 'сверло', 'свернуть', 'сверкают'] as $word) {
            self::assertFalse(AssistantReportComparisonSpecification::requestsComparison(
                'Расположи все акты организации '.$word.', а договоры проекта 100 снизу'), $word);
        }
        self::assertFalse(AssistantReportComparisonSpecification::requestsComparison(
            'Все акты организации, а также договоры проекта 100 с полями номер и статус сверки'));
        foreach (['Нужна сверка', 'Проведи сверку', 'Выполнить сверку', 'Данные для сверки'] as $prefix) {
            self::assertTrue(AssistantReportComparisonSpecification::requestsComparison($prefix.' актов организации с проектом 100'), $prefix);
        }
        foreach (['сверь', 'сверьте', 'сверять', 'сверяем', 'сверяйте', 'сверить', 'сверим', 'сверят', 'сверка', 'сверки', 'сверок'] as $word) {
            self::assertTrue(AssistantReportComparisonSpecification::requestsComparison(
                $word.' все акты организации с проектом 100'), $word);
        }
    }

    public function test_comparison_relations_allow_scope_prepositions_between_entity_and_project(): void
    {
        foreach (['Сравни все акты организации с актами по проекту 100',
            'Сверь все акты организации с актами для выбранного проекта',
            'Сопоставь все акты организации и акты из проекта 100',
            'Сравни все акты организации с актами в выбранном проекте'] as $question) {
            $id = str_contains($question, '100') ? 100 : 52;
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
            self::assertSame(['project_id' => $id], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => $id], 52, true));
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => $id, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        }
    }

    public function test_comparisons_require_project_counterparts_for_every_organization_subject(): void
    {
        $question = 'Сравни все акты организации и все договоры организации с проектом 100';
        $organizationAct = ['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'];
        $organizationContract = ['scope' => 'organization', 'project_id' => null, 'domain' => 'contracts', 'entity_type' => 'contract'];
        $projectAct = array_replace($organizationAct, ['scope' => 'project', 'project_id' => 100]);
        $projectContract = array_replace($organizationContract, ['scope' => 'project', 'project_id' => 100]);
        $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
        $complete = ['sections' => [$organizationAct, $organizationContract, $projectAct, $projectContract]];
        self::assertSame($complete, $scope->bindReportScope($question, $complete));
        $this->expectException(AssistantReportScopeAmbiguous::class);
        $scope->bindReportScope($question, ['sections' => [$organizationAct, $organizationContract, $projectAct]]);
    }

    public function test_negative_project_directives_before_the_operand_keep_organization_scope(): void
    {
        foreach (['не включай проект 100', 'не учитывай проект 100', 'исключи проект 100',
            'не включайте выбранный проект', 'не учитывай проект «СевГУ»'] as $clause) {
            $question = 'Покажи все акты организации, '.$clause;
            $id = str_contains($clause, 'выбранный') ? 52 : 100;
            $search = static function (array $arguments): array {
                self::assertSame(['query' => 'СевГУ', 'name_match' => 'exact', 'limit' => 2], $arguments);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => $id]]]]];
            $expected = $arguments;
            $expected['sections'][0]['project_id'] = null;
            self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search));
        }
    }

    public function test_postpositive_project_exclusions_keep_organization_scope_and_report_constraints(): void
    {
        foreach (['проект 100 не включай', 'проект 100 не включать', 'проект 100 не учитывайте',
            'проект 100 не учитывать', 'проект 100 исключить', 'проект 100 исключи из отчёта',
            'проект 100, пожалуйста, не включай', 'выбранный проект не включай',
            'проект «СевГУ» не включай', 'проект СевГУ не учитывать',
            ...array_map(static fn (string $format): string => 'проект 100 не включай в '.$format, ['PDF', 'pdf', 'pDf', 'CSV', 'XLSX', 'Excel']),
            'выбранный проект не включай в PDF', 'проект «СевГУ» не включай в PDF'] as $clause) {
            $question = 'Покажи все акты организации, '.$clause;
            $id = str_contains($clause, 'выбранный') ? 52 : 100;
            $search = static function (array $arguments): array {
                self::assertSame(['query' => 'СевГУ', 'name_match' => 'exact', 'limit' => 2], $arguments);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertFalse($scope->requested);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => $id]]]]];
            $expected = $arguments;
            $expected['sections'][0]['project_id'] = null;
            self::assertSame($expected, $scope->bindReportScope($question, $arguments, $search), $question);
            try {
                $scope->bindReportScope($question, ['sections' => [['project_id' => 52, 'filters' => []]]], $search);
                self::fail('Postpositive project exclusion omitted from report filters');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_postpositive_project_exclusions_require_clarification_before_search(): void
    {
        foreach (['проект 100 не включай', 'выбранный проект исключить', 'проект «СевГУ» не учитывать',
            'проект 100 не включай в PDF', 'выбранный проект не включай в PDF', 'проект «СевГУ» не учитывай в PDF'] as $clause) {
            $search = static function (): never {
                self::fail('Excluded project reached a positive search');
            };
            try {
                AssistantRequestProjectScope::bindSearchScope('Покажи все акты организации, '.$clause, ['project_id' => 52], 52, true, $search);
                self::fail('Search without exclusion support accepted a postpositive exclusion');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_postpositive_exclusion_commands_preserve_literal_names_and_other_objects(): void
    {
        foreach (['Все акты организации по проекту «СевГУ не включай»',
            'Все акты организации по проекту «Проект 100 исключить»',
            'Все акты организации по проекту 100, не включай черновики',
            'Все акты организации по проекту 100 — не учитывай черновики'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
        }
    }

    public function test_comparison_relations_allow_a_repeated_registered_entity_before_the_project(): void
    {
        foreach (['Сравни все акты организации с актами проекта 100',
            'Сверь все акты организации с актами выбранного проекта',
            'Сопоставь акты выбранного проекта со всеми актами организации',
            'Сравни все акты организации и акты проекта 100',
            'Сверь акты проекта 100 и все акты организации'] as $question) {
            $id = str_contains($question, '100') ? 100 : 52;
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
            self::assertSame(['project_id' => $id], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => $id], 52, true));
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => $id, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        }
    }

    public function test_comparison_entity_operands_do_not_accept_unknown_negative_or_quoted_phrases(): void
    {
        foreach (['не актами', 'без актов', 'неизвестными сущностями', '«актами»'] as $entity) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide('Сравни все акты организации с '.$entity.' проекта 100'));
        }
        self::assertFalse(AssistantRequestProjectScope::organizationWide('Сведения проекта «Сравни все акты организации с актами проекта 100»'));
    }

    public function test_reconciliation_verbs_preserve_organization_and_project_comparison(): void
    {
        foreach (['Сверь все акты организации с проектом 100', 'Сверьте все акты организации с проектом 100',
            'Нужна сверка всех актов организации с проектом 100',
            'Сверь выбранный проект со всеми актами организации'] as $question) {
            $id = str_contains($question, '100') ? 100 : 52;
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
            self::assertSame(['project_id' => $id], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => $id], 52, true));
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => $id, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        }
    }

    public function test_project_first_comparison_commands_preserve_both_scopes(): void
    {
        foreach (['Все акты проекта «Сравни» со всеми актами организации',
            'Все акты проекта Сверка со всеми актами организации',
            'Все акты проекта 100 не сравни со всеми актами организации',
            'Все акты выбранного проекта не сравни со всеми актами организации',
            'Все акты проекта 100 сравни с актами проекта 52',
            'Все акты проекта «Альфа сравни со всеми актами организации»'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
        }
        foreach (['Все акты проекта 100 сравни со всеми актами организации',
            'Все акты выбранного проекта сравни со всеми актами организации',
            'Все акты проекта «СевГУ» сравни со всеми актами организации',
            'Все акты объекта №100 сравните со всеми актами организации',
            'Все акты проекта 100 сопоставь со всеми актами организации',
            'Все акты проекта 100 сверь со всеми актами организации'] as $question) {
            $id = str_contains($question, 'выбранного') ? 52 : 100;
            $search = static fn (): array => ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true, $search));
            self::assertSame(['project_id' => $id], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => $id], 52, true, $search));
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => $id, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search));
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                    ['sections' => [['scope' => 'project', 'project_id' => $id, 'domain' => 'acts', 'entity_type' => 'performance_act']]], $search);
                self::fail('Project-first comparison lost its organization section');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_comparison_relations_can_continue_a_complete_organization_clause(): void
    {
        foreach (['с', 'со', 'против', 'относительно'] as $relation) {
            foreach (['все акты организации', 'все акты нашей организации', 'всей организации'] as $organization) {
                $question = 'Сравни '.$organization.' '.$relation.' '.(in_array($relation, ['с', 'со'], true) ? 'проектом' : 'проекта').' 100';
                self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
                self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
                self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 100], 52, true));
                $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
                self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
            }
        }
        foreach (['противень', 'относительная'] as $word) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide('Сравни все акты организации '.$word.' проекта 100'));
        }
    }

    public function test_wrapped_entity_phrases_preserve_both_comparison_operands(): void
    {
        foreach (['данными по актам', 'сведениями по актам', 'информацией по договорам',
            'данными по сведениям по актам'] as $phrase) {
            $question = 'Сравни все акты организации '.(str_starts_with($phrase, 'сведениями') ? 'со' : 'с').' '.$phrase.' проекта 100';
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
            self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 100], 52, true));
            $contract = str_contains($phrase, 'договорам');
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 100, 'domain' => $contract ? 'contracts' : 'acts', 'entity_type' => $contract ? 'contract' : 'performance_act']]];
            if ($contract) {
                try {
                    AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments);
                    self::fail('Incompatible requested subjects accepted');
                } catch (AssistantReportScopeAmbiguous) {
                    self::assertTrue(true);
                }

                continue;
            }
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        }
        foreach (['чужими данными по актам', 'данными чужой организации по актам', 'данными по неизвестным сущностям',
            'не данными по актам', 'колонками по актам'] as $phrase) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide('Сравни все акты организации с '.$phrase.' проекта 100'), $phrase);
        }
    }

    public function test_postpositive_comparison_negation_allows_heterogeneous_report_sections(): void
    {
        foreach (['Сравнивать их не нужно', 'Сопоставлять их не надо', 'Сверять данные между собой не требуется',
            'Сравнение этих данных не нужно', 'Сравнивать между собой не стоит', 'Сопоставлять — не нужно',
            'Сравнивать их сейчас не надо', 'Сверка не нужна'] as $clause) {
            $question = 'Все акты организации, а также договоры проекта 100. '.$clause;
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments), $clause);
        }
    }

    public function test_postpositive_negation_does_not_remove_a_separate_comparison(): void
    {
        foreach (['Все акты организации, а также договоры проекта 100. Сравнивать их не нужно. Но сверь итоги.',
            'Сравни все акты организации с проектом 100. Файл создавать не нужно.'] as $question) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [
                    ['scope' => 'organization', 'project_id' => null, 'domain' => 'acts'],
                    ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts'],
                ]]);
                self::fail('An independent affirmative comparison was removed');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_project_list_first_comparison_requires_each_project_and_organization(): void
    {
        $question = 'Все акты проектов 100 и 102 сравни со всеми актами организации';
        self::assertTrue(AssistantRequestProjectScope::organizationWide($question));
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 102, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        array_pop($arguments['sections']);
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments);
    }

    public function test_reconciliation_comparisons_reject_incomparable_subjects(): void
    {
        $question = 'Сверь все акты организации и проект 100';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [
            ['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract'],
        ]]);
    }

    public function test_comparison_sections_require_the_same_subject_specification(): void
    {
        $question = 'Сравни все акты организации с проектом 100';
        $subject = ['domain' => 'acts', 'entity_type' => 'performance_act', 'columns' => ['status'],
            'filters' => [['field' => 'status', 'operator' => 'neq', 'value' => 'rejected']],
            'group_by' => ['status', 'currency'], 'metrics' => [['field' => 'id', 'operation' => 'count']],
            'order_by' => [['field' => 'status', 'direction' => 'asc']], 'row_limit' => 100];
        foreach (['domain' => 'contracts', 'entity_type' => 'contract', 'columns' => ['currency'],
            'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']],
            'group_by' => ['status'], 'metrics' => [['field' => 'total_amount', 'operation' => 'sum']],
            'order_by' => [['field' => 'status', 'direction' => 'desc']], 'row_limit' => 1] as $key => $value) {
            $organization = ['scope' => 'organization', 'project_id' => null, ...$subject];
            $project = ['scope' => 'project', 'project_id' => 100, ...array_replace($subject, [$key => $value])];
            try {
                AssistantReportComparisonSpecification::assertCompatible($question, [$organization, $project], [100]);
                self::fail('Different comparison subject accepted: '.$key);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_project_column_lists_do_not_restrict_organization_scope(): void
    {
        foreach (['Сделай все акты организации с колонками проект и статус',
            'Покажи все акты организации с полями проект и валюта',
            'Сделай все акты организации со столбцами проект и сумма',
            'Все акты организации сгруппируй по полям проект и статус',
            'Все акты организации сгруппируй по проекту и статусу',
            'Все акты организации в разрезе проекта и статуса',
            'Все акты организации с детализацией по проекту и статусу',
            'Все акты организации в разрезе проекта и валюты',
            'Все договоры организации в разрезе проекта и подрядчика',
            'Все договоры организации с детализацией по проекту и контрагенту',
            'Все договоры организации в разрезе проекта, подрядчика и даты окончания',
            'Все договоры организации в разрезе проекта и сотрудника',
            'Все акты организации с детализацией по проекту и дате',
            'Все акты организации с колонками номер, проект и статус'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $search = static function (): never {
                self::fail('Column name triggered project lookup');
            };
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true, $search));
            self::assertSame(['sections' => [['project_id' => null]]], AssistantRequestProjectScope::fromPlan($this->plan($question))
                ->bindReportScope($question, ['sections' => [['project_id' => 52]]], $search));
        }
    }

    public function test_project_grouping_uses_registered_field_labels_without_consuming_literal_names(): void
    {
        app('translator')->get('ai_assistant_facts.fields', [], 'ru');
        app('translator')->addLines(['ai_assistant_facts.fields.scope_sector' => 'Сектор',
            'ai_assistant_facts.fields.scope_category' => 'Категория'], 'ru');
        foreach (['сектора', 'категории', 'даты окончания', 'номера договора'] as $field) {
            $question = 'Все договоры организации в разрезе проекта и '.$field;
            self::assertNull(AssistantReportEntityQuery::extract($question, 'project'), $field);
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $field);
        }
        foreach (['И Контрагента Корпуса Б', 'И Сектора Корпуса Б', 'И Категории Север'] as $name) {
            self::assertSame($name, AssistantReportEntityQuery::extract('Все договоры организации в разрезе проекта '.$name, 'project', true));
        }
        foreach (['И Подрядчика', 'И Контрагенту'] as $name) {
            self::assertSame($name, AssistantReportEntityQuery::extract('Все договоры организации в разрезе проекта «'.$name.'»', 'project'));
        }
    }

    public function test_comparison_subject_preserves_equivalent_field_sets_metric_labels_and_aliases(): void
    {
        $question = 'Сравни все акты организации с проектом 100';
        $organization = ['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'columns' => ['status', 'currency'], 'group_by' => ['status', 'currency'],
            'filters' => [['field' => 'status', 'operator' => 'in', 'value' => ['approved', 'draft']],
                ['field' => 'currency', 'operator' => 'eq', 'value' => 'RUB']],
            'metrics' => [['field' => 'id', 'operation' => 'count', 'label' => 'Количество организации'],
                ['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма организации']],
            'order_by' => [['field' => 'metric_2', 'direction' => 'desc']], 'row_limit' => 1000];
        $project = array_replace($organization, ['scope' => 'project', 'project_id' => 100,
            'columns' => ['currency', 'status'], 'group_by' => ['currency', 'status'],
            'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 100],
                ['field' => 'currency', 'operator' => 'eq', 'value' => 'RUB'],
                ['field' => 'status', 'operator' => 'in', 'value' => ['draft', 'approved', 'draft']]],
            'metrics' => [['field' => 'total_amount', 'operation' => 'sum', 'label' => 'Сумма проекта'],
                ['field' => 'id', 'operation' => 'count', 'label' => 'Количество проекта']],
            'order_by' => [['field' => 'metric_1', 'direction' => 'desc']]]);
        unset($project['row_limit']);
        $arguments = ['sections' => [$organization, $project]];
        AssistantReportComparisonSpecification::assertCompatible($question, $arguments['sections'], [100]);
        self::assertTrue(true);
        $arguments['sections'][1]['order_by'][0]['field'] = 'metric_2';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantReportComparisonSpecification::assertCompatible($question, $arguments['sections'], [100]);
    }

    public function test_noun_comparisons_require_pairwise_subjects(): void
    {
        foreach (['различия', 'разницу'] as $noun) {
            $question = 'Покажи '.$noun.' между всеми актами организации и актами проекта 100';
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertSame(['project_ids' => [100], 'pairwise' => true, 'subjects' => [
                '["organization","acts","performance_act"]' => true, '["project:100","acts","performance_act"]' => true]], $scope->searchCoverageRequirements($question));
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']];
            try {
                $scope->bindReportScope($question, ['sections' => $sections]);
                self::fail('Different subjects accepted for '.$noun);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
            $sections[1]['domain'] = 'acts';
            $sections[1]['entity_type'] = 'performance_act';
            self::assertSame(['sections' => $sections], $scope->bindReportScope($question, ['sections' => $sections]));
        }
    }

    public function test_comparison_nouns_keep_negation_literal_names_and_adjective_boundaries(): void
    {
        foreach (['Покажи различные акты организации и договоры проекта 100',
            'Все акты организации, а также договоры проекта 100 с колонками номер и разница',
            'Все акты организации, а также договоры проекта 100 с полями номер и различия',
            'Не показывай основные различия между всеми актами организации и актами проекта 100',
            'Не нужно показать ключевую разницу между всеми актами организации и актами проекта 100',
            'Различия не нужны', 'Разница не нужна', 'Без различий', 'Без разницы',
            'Не показывай разницу', 'Не нужно показывать различия', 'Не нужна разница',
            'Все акты организации, а также договоры проекта «Различия»',
            'Все акты организации, а также договоры проекта «Разница»'] as $question) {
            self::assertFalse(AssistantReportComparisonSpecification::requestsComparison($question), $question);
        }
        foreach (['Различия', 'Разница'] as $name) {
            self::assertFalse(AssistantReportComparisonSpecification::requestsComparison(
                'Все акты организации, а также договоры проекта '.$name, [$name]));
        }
        self::assertTrue(AssistantReportComparisonSpecification::requestsComparison('Не показывай разницу, но сравни все акты организации с проектом 100'));
        foreach (['Покажи разницу.', 'Найди основные различия.', 'В чём разница?',
            'Различия между всеми актами организации и актами проекта 100'] as $question) {
            self::assertTrue(AssistantReportComparisonSpecification::requestsComparison($question), $question);
        }
    }

    public function test_each_comparison_subject_requires_all_compared_projects(): void
    {
        $question = 'Сравни все акты организации с проектами 52 и 100';
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts'],
            ['scope' => 'organization', 'project_id' => null, 'domain' => 'contracts'],
            ['scope' => 'project', 'project_id' => 52, 'domain' => 'acts'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts']];
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
    }

    public function test_heterogeneous_sections_remain_available_without_an_affirmative_comparison(): void
    {
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']]];
        foreach (['Все акты организации, а также по проекту 100 — договоры',
            'Все акты организации, а также по проекту 100 — договоры. Не сравнивай их.',
            'Все акты организации, а также по проекту «Сравни» — договоры',
            'Все акты организации, а также по проекту 100 — договоры. Не сверяй их.',
            'Все акты организации, а также по проекту «Сверка» — договоры'] as $question) {
            $search = static fn (): array => ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search), $question);
        }
    }

    public function test_unquoted_resolved_project_names_do_not_request_subject_comparison(): void
    {
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
        foreach (['Сверка', 'Сравнение', 'Сравни'] as $name) {
            $question = 'Все акты организации, а также по проекту '.$name;
            $search = static function (array $arguments) use ($name): array {
                self::assertSame(['query' => $name, 'name_match' => 'exact', 'limit' => 2], $arguments);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search), $question);
        }
    }

    public function test_resolved_project_names_preserve_separate_comparison_commands(): void
    {
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract']]];
        foreach (['Сверка', 'Сравнение', 'Сравни', '100'] as $name) {
            $question = $name === '100' ? 'Все акты организации, а также по проекту 100. Сверь их.'
                : 'Сравни все акты организации с проектом '.$name;
            $search = static function (array $arguments) use ($name): array {
                self::assertSame(['query' => $name, 'name_match' => 'exact', 'limit' => 2], $arguments);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search);
                self::fail('Actual comparison command hidden by project name: '.$name);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
    }

    public function test_column_context_preserves_actual_project_names(): void
    {
        foreach (['И Статус', '«И Статус»', '«Колонки проект и статус»'] as $name) {
            $question = 'Все акты организации по проекту '.$name;
            self::assertSame(trim($name, '«»'), AssistantReportEntityQuery::extract($question, 'project'));
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
        }
        self::assertSame('И Статус', AssistantReportEntityQuery::extract('С полями номер и сумма для проекта И Статус', 'project'));
        self::assertSame('И Статус', AssistantReportEntityQuery::extract('С колонками проект «И Статус»', 'project'));
        foreach (['Все акты организации в разрезе проекта «И Статуса»' => 'И Статуса',
            'Все акты организации с детализацией по проекту «И Статусу»' => 'И Статусу',
            'Все акты организации по проекту И Статуса' => 'И Статуса',
            'Все акты организации в разрезе проекта И Статуса Корпуса Б' => 'И Статуса Корпуса Б',
            'Все акты организации в разрезе проекта И Север' => 'И Север',
            'Все акты организации в разрезе проекта И Статус' => 'И Статус'] as $question => $name) {
            self::assertSame($name, AssistantReportEntityQuery::extract($question, 'project'), $question);
            self::assertSame($name, AssistantReportEntityQuery::extract($question, 'project', true), $question);
        }
    }

    public function test_project_and_object_adjectives_do_not_request_all_projects(): void
    {
        foreach (['Сгруппируй задачи по всем проектным статусам', 'Покажи акты во всех объектных категориях',
            'Сводка всех проектных статусов', 'Сравни договоры со всеми объектными требованиями',
            'Покажи задачи любого из наших проектных статусов', 'Покажи задачи по всем проектировщикам',
            'Покажи акты по всем объектамучёта'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(52, AssistantRequestProjectScope::fromPlan($this->plan($question))->projectId, $question);
            self::assertSame(['project_id' => 52], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
        }
    }

    public function test_complete_project_and_object_nouns_keep_all_project_scope(): void
    {
        foreach (['Покажи договоры во всех проектах', 'Покажи договоры во всех объектах',
            'Сводка всех проектов', 'Сводка всех объектов', 'Покажи акты по всем проектам',
            'Покажи акты по всем объектам', 'Отчёт со всеми проектами', 'Отчёт со всеми нашими объектами',
            'Выбери договор любого из наших проектов', 'Выбери любой наш объект',
            'Покажи все проекты', 'Покажи все объекты'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
        }
    }

    public function test_instrumental_organization_records_preserve_both_comparison_scopes(): void
    {
        foreach (['со всеми актами организации' => ['acts', 'performance_act'],
            'со всеми договорами организации' => ['contracts', 'contract'],
            'со всеми нашими журналами организации' => ['core_journals', 'construction_journal'],
            'со всеми сотрудниками организации' => ['people', 'user'],
            'со всеми записями времени организации' => ['time_tracking', 'time_entry'],
            'со всеми поставками организации' => ['warehouse', 'project_material_delivery']] as $records => [$domain, $type]) {
            $question = 'Сравни выбранный проект '.$records;
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertFalse($scope->requested);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => null], 52, true));
            self::assertSame(['project_id' => 52], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true));
            $source = ['domain' => $domain, 'entity_type' => $type];
            $arguments = ['sections' => [array_replace($source, ['scope' => 'organization', 'project_id' => null]),
                array_replace($source, ['scope' => 'project', 'project_id' => 52])]];
            if (! in_array($type, ['performance_act', 'contract'], true)) {
                try {
                    $scope->bindReportScope($question, $arguments);
                    self::fail('Unresolved source was accepted: '.$records);
                } catch (AssistantReportScopeAmbiguous) {
                    self::assertTrue(true);
                }
            } else {
                self::assertSame($arguments, $scope->bindReportScope($question, $arguments), $question);
            }
        }
    }

    public function test_instrumental_records_inside_literal_project_names_do_not_expand_the_scope(): void
    {
        foreach (['Покажи камеры проекта «Со всеми актами организации»', 'Покажи камеры проекта Со всеми актами организации',
            'Покажи камеры объекта: Со всеми журналами нашей организации'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
        }
    }

    public function test_comparing_values_within_one_explicit_project_does_not_request_organization_comparison(): void
    {
        foreach (['Сравни статусы всех актов организации по проекту 100',
            'Сопоставь суммы всех актов организации в проекте СевГУ'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
            $search = static fn (): array => ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true, $search), $question);
            self::assertSame(['sections' => [['project_id' => 100]]], AssistantRequestProjectScope::fromPlan($this->plan($question))
                ->bindReportScope($question, ['sections' => [['project_id' => 52]]], $search), $question);
        }
    }

    public function test_organization_report_rejects_unrequested_negative_and_null_project_filters(): void
    {
        foreach (['Все акты организации', 'Сравни все акты организации с проектом 100'] as $question) {
            foreach (['neq', 'is_null', 'is_not_null'] as $operator) {
                $section = ['scope' => 'organization', 'project_id' => null,
                    'filters' => [['field' => 'project_id', 'operator' => $operator, 'value' => $operator === 'neq' ? 52 : null]]];
                $sections = [$section];
                if (str_starts_with($question, 'Сравни')) {
                    $sections[] = ['scope' => 'project', 'project_id' => 100];
                }
                try {
                    AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                    self::fail('An unrequested project filter was accepted: '.$operator);
                } catch (AssistantReportScopeAmbiguous) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function test_comparing_values_by_multiple_restricted_projects_does_not_add_an_organization_section(): void
    {
        $question = 'Сравни статусы всех актов организации по проектам №52 и №100';
        self::assertFalse(AssistantRequestProjectScope::organizationWide($question));
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [
            ['scope' => 'organization', 'project_id' => null], ['scope' => 'project', 'project_id' => 52], ['scope' => 'project', 'project_id' => 100],
        ]]);
    }

    public function test_organization_project_filters_match_requested_ids_and_nullness_in_every_section(): void
    {
        foreach ([['Все акты организации, кроме проекта 100', [['field' => 'project_id', 'operator' => 'neq', 'value' => 100]]],
            ['Все акты организации, кроме проектов 100 и 101', [['field' => 'project_id', 'operator' => 'neq', 'value' => 100], ['field' => 'project_id', 'operator' => 'neq', 'value' => 101]]],
            ['Все акты организации, кроме проекта «СевГУ»', [['field' => 'project_id', 'operator' => 'neq', 'value' => 100]]],
            ['Все акты организации, без выбранного проекта', [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]],
            ['Все акты организации, только без проекта', [['field' => 'project_id', 'operator' => 'is_null', 'value' => null]]],
            ['Все акты организации, только с указанным проектом', [['field' => 'project_id', 'operator' => 'is_not_null', 'value' => null]]]] as [$question, $filters]) {
            $search = static function (array $arguments): array {
                self::assertSame('СевГУ', $arguments['query']);
                self::assertSame('exact', $arguments['name_match']);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            $arguments = ['sections' => [['project_id' => null, 'filters' => $filters]]];
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertSame($arguments, $scope->bindReportScope($question, $arguments, $search), $question);
            foreach ([[], [['field' => 'project_id', 'operator' => 'neq', 'value' => 999]]] as $wrong) {
                try {
                    $scope->bindReportScope($question, ['sections' => [$arguments['sections'][0], ['project_id' => null, 'filters' => $wrong]]], $search);
                    self::fail('Missing or mismatched requested project constraints were accepted');
                } catch (AssistantReportScopeAmbiguous) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function test_including_projectless_records_does_not_authorize_a_null_only_filter(): void
    {
        $question = 'Все договоры организации. Договоры без проекта тоже учти.';
        $arguments = ['sections' => [['project_id' => null, 'filters' => []]]];
        $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
        self::assertSame($arguments, $scope->bindReportScope($question, $arguments));
        $this->expectException(AssistantReportScopeAmbiguous::class);
        $scope->bindReportScope($question, ['sections' => [['project_id' => null,
            'filters' => [['field' => 'project_id', 'operator' => 'is_null', 'value' => null]]]]]);
    }

    public function test_negated_project_prepositions_never_become_positive_search_restrictions(): void
    {
        foreach (['но не по проекту 100', 'не в проекте 100', 'не для проекта #100',
            'не по проекту «СевГУ»', 'не в проекте СевГУ', 'не по выбранному проекту'] as $clause) {
            $question = 'Покажи все акты организации, '.$clause;
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $search = static function (array $arguments): array {
                self::assertSame('СевГУ', $arguments['query']);
                self::assertSame('exact', $arguments['name_match']);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            try {
                AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true, $search);
                self::fail('A project exclusion cannot be represented by a positive search project_id');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'neq',
                'value' => str_contains($clause, 'выбранному') ? 52 : 100]]]]];
            $expected = $arguments;
            $expected['sections'][0]['project_id'] = null;
            self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search), $question);
        }
    }

    public function test_additive_project_scopes_remain_mixed_without_a_comparison_verb(): void
    {
        foreach (['Все акты организации и проекта 100', 'Покажи акты проекта 100, а также по всем актам организации',
            'Покажи акты проекта 100, а также по всем нашим проектам'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
                static fn (): array => ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]]), $question);
        }
    }

    public function test_explicit_single_project_restriction_overrides_organization_wording(): void
    {
        foreach (['Покажи все акты организации по проекту 100', 'Все акты организации в проекте №100',
            'Покажи все акты организации в проекте СевГУ', 'Все акты организации по объекту «СевГУ»',
            'Все проекты организации по проекту 100'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
            $search = static function (array $arguments): array {
                self::assertContains($arguments['query'], ['100', 'СевГУ']);

                return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
            };
            self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope($question, [], 52, true, $search), $question);
            self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true, $search), $question);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 52],
                ['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]]]];
            $expected = ['sections' => [['project_id' => 100, 'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'approved']]]]];
            self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search), $question);
        }
    }

    public function test_explicit_single_project_restriction_never_falls_back_to_ui_or_organization(): void
    {
        foreach (['Все акты организации по проекту 100', 'Все акты организации в проекте СевГУ'] as $question) {
            foreach ([null, ['status' => 'error'], ['status' => 'success', 'results' => [], 'result_window' => ['has_more' => false]],
                ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => true]],
                ['status' => 'success', 'results' => [['id' => 100], ['id' => 101]], 'result_window' => ['has_more' => false]]] as $result) {
                foreach (['search', 'report'] as $operation) {
                    try {
                        $search = static fn (): mixed => $result;
                        if ($operation === 'search') {
                            AssistantRequestProjectScope::bindSearchScope($question, [], 52, true, $search);
                        } else {
                            AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [['project_id' => 52]]], $search);
                        }
                        self::fail('An unverified explicit project was accepted');
                    } catch (AssistantReportScopeAmbiguous) {
                        self::assertTrue(true);
                    }
                }
            }
        }
    }

    public function test_not_including_project_is_an_exclusion_without_a_positive_lookup(): void
    {
        foreach (['не включая проект 52', 'не включая проект №52', 'не включая выбранный проект',
            'не включая проект «СевГУ»', 'не включая проект СевГУ'] as $clause) {
            $question = 'Сделай отчёт по всем актам организации, '.$clause;
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $search = static function (array $arguments): array {
                self::assertSame('СевГУ', $arguments['query']);
                self::assertSame('exact', $arguments['name_match']);

                return ['status' => 'success', 'results' => [['id' => 52]], 'result_window' => ['has_more' => false]];
            };
            try {
                AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true, $search);
                self::fail('A project exclusion cannot be represented by a positive search project_id');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]]]];
            $expected = $arguments;
            $expected['sections'][0]['project_id'] = null;
            self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search), $question);
        }
    }

    public function test_single_project_restriction_rejects_conflicting_filters_or_a_wrong_id_lookup(): void
    {
        $question = 'Все акты организации по проекту 100';
        foreach ([['project_id' => 200], ['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 200]]],
            ['filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 100]]],
            ['filters' => [['field' => 'project_id', 'operator' => 'is_null']]],
            ['filters' => [['field' => 'project_id', 'operator' => 'gt', 'value' => 100]]]] as $section) {
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => [$section]],
                    static fn (): array => ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]]);
                self::fail('A conflicting project constraint was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
        }
        try {
            AssistantRequestProjectScope::bindSearchScope($question, [], 52, true,
                static fn (): array => ['status' => 'success', 'results' => [['id' => 101]], 'result_window' => ['has_more' => false]]);
            self::fail('A lookup of a different project ID was accepted');
        } catch (AssistantReportScopeAmbiguous) {
            self::assertTrue(true);
        }
    }

    public function test_collective_organization_entities_clear_the_selected_project(): void
    {
        foreach (['Покажи всю технику организации', 'Вся техника организации', 'По всей технике нашей организации',
            'Сводка всего оборудования организации', 'Покажи всё наше оборудование организации',
            'Покажи всю исправную технику организации', 'Вся арендованная техника нашей организации',
            'Покажи весь персонал организации', 'По всему персоналу нашей организации',
            'Вся выработка организации за октябрь', 'Покажи всю подтверждённую выработку организации',
            'Всё рабочее время организации', 'По всему рабочему времени организации',
            'Вся документация организации', 'Всю исполнительную документацию нашей организации в Excel',
            'Вся проектная документация организации', 'Всё качество организации', 'Вся безопасность организации'] as $question) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertNull(AssistantRequestProjectScope::fromPlan($this->plan($question))->projectId, $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => [['field' => 'status', 'operator' => 'eq', 'value' => 'active']]]]];
            $expected = $arguments;
            $expected['sections'][0]['project_id'] = null;
            self::assertSame($expected, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments), $question);
        }
    }

    public function test_collective_phrases_keep_project_names_negation_and_process_boundaries(): void
    {
        foreach (['Всю технику другой организации', 'Всю технику организаций', 'Покажи всю технику организации работ',
            'Вся документация организации строительства', 'Всё оборудование организации документооборота',
            'Не показывай всю технику организации', 'Кроме всего оборудования организации',
            'Вся техника организации, только выбранного проекта',
            'Покажи технику проекта «Вся техника организации»', 'Покажи технику проекта Вся техника организации',
            'Покажи технику объекта: Всё оборудование организации', 'Покажи всю неизвестную сущность организации'] as $question) {
            self::assertFalse(AssistantRequestProjectScope::organizationWide($question), $question);
            self::assertSame(['project_id' => 52], AssistantRequestProjectScope::bindSearchScope($question, [], 52, true), $question);
        }
    }

    public function test_work_organization_phrases_do_not_expand_the_selected_project(): void
    {
        $questions = ['Покажи все задачи организации работ', 'Все задачи организации строительства',
            'По всем задачам организации строительных работ', 'Покажи все просроченные задачи организации работ',
            'Все задачи организации проекта', 'Все задачи организации документооборота',
            'Все задачи организации хранения', 'Все задачи организации безопасности',
            'Все задачи организации безопасного обращения с материалами', 'Все задачи организации мобилизации',
            'Все задачи организации снабжения', 'Все задачи организации отчётности',
            'Все задачи организации не завершённых работ'];
        self::assertSame(array_fill(0, count($questions), false), array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            self::assertSame(['project_id' => 52], AssistantRequestProjectScope::bindSearchScope($question, [], 52, true), $question);
            $arguments = ['sections' => [['project_id' => 52, 'filters' => []]]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments), $question);
        }
    }

    public function test_organization_record_scope_allows_filters_commands_and_output_after_the_company(): void
    {
        $questions = ['Все акты организации в CSV', 'Все акты организации по статусам',
            'Все задачи организации за октябрь', 'Все договоры организации с группировкой по статусам',
            'Все акты организации без черновиков', 'Все акты организации, включая черновики',
            'Все акты организации сгруппируй по статусам', 'По всем актам организации подготовьте CSV',
            'Все акты организации не включай черновики', 'Все акты организации нужны в CSV',
            'Все акты организации пожалуйста подготовь Excel', 'Все акты организации CSV',
            'Все акты организации утверждённые', 'Все договоры организации частично оплаченные',
            'Все акты организации не подписанные', 'Все акты организации отчёт в CSV',
            'Все акты организации не ограничивайся выбранным проектом', 'Все акты организации экспортируй в CSV',
            'Все акты организации сохраните в Excel'];
        self::assertSame(array_fill(0, count($questions), true), array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
        }
    }

    public function test_nominative_project_and_object_lists_clear_the_selected_project(): void
    {
        $questions = ['Покажи все проекты организации', 'Все объекты организации', 'Покажи все наши проекты',
            'Все наши объекты', 'Покажи все проекты', 'Покажи все объекты',
            'Все активные проекты организации', 'Все наши незавершённые объекты организации',
            'Все частично завершённые проекты организации', 'По всем активным проектам организации'];
        self::assertSame(array_fill(0, count($questions), true), array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
        foreach ($questions as $question) {
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            self::assertNull($scope->projectId, $question);
            self::assertSame(['project_id' => null], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 52], 52, true), $question);
            self::assertSame(['sections' => [['project_id' => null, 'filters' => []]]],
                $scope->bindReportScope($question, ['sections' => [['project_id' => 52, 'filters' => []]]]), $question);
        }
    }

    public function test_nominative_project_scope_keeps_negation_and_literal_names(): void
    {
        $questions = ['Не показывай все проекты организации', 'Кроме всех объектов нашей организации',
            'Покажи акты проекта «Все проекты организации»', 'Все проектировщики выбранного проекта',
            'Все объектовые документы выбранного проекта', 'Все выбранные проекты'];
        self::assertSame(array_fill(0, count($questions), false), array_map(AssistantRequestProjectScope::organizationWide(...), $questions));
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

    public function test_report_binding_removes_selected_project_ids_and_keeps_non_project_filters(): void
    {
        $question = 'По всем актам организации. Не ограничивайся выбранным проектом.';
        $filters = [['field' => 'project_id', 'operator' => 'eq', 'value' => 52],
            ['field' => 'date', 'operator' => 'gte', 'value' => '2026-10-01'],
            ['field' => 'status', 'operator' => 'neq', 'value' => 'rejected']];
        $arguments = ['title' => 'Акты', 'format' => 'csv', 'sections' => [
            ['project_id' => 52, 'filters' => $filters, 'columns' => ['status', 'currency']],
            ['project_id' => 52, 'filters' => [['field' => 'project_id', 'operator' => 'in', 'value' => ['52']]]],
        ]];
        $expected = $arguments;
        $expected['sections'][0]['project_id'] = null;
        $expected['sections'][0]['filters'] = array_slice($filters, 1);
        $expected['sections'][1] = ['project_id' => null, 'filters' => []];
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
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []],
            ['scope' => 'project', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []]]];
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
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []], ['scope' => 'project', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []]]];
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
        $arguments = ['sections' => [['title' => 'По всей организации', 'scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []],
            ['title' => 'Выбранный проект', 'scope' => 'project', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []]]];
        self::assertSame($arguments, $scope->bindReportScope($question, $arguments));
    }

    public function test_explicit_comparison_clears_only_the_stale_organization_section(): void
    {
        $question = 'Сравни отчёт по всем актам организации с проектом 100';
        $arguments = ['sections' => [['title' => 'По всей организации', 'scope' => 'organization', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act',
            'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 52]]],
            ['title' => 'Проект 100', 'scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => [['field' => 'project_id', 'operator' => 'eq', 'value' => 100]]]]];
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
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []],
            ['scope' => 'project', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => []]]];
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

    public function test_project_sections_reject_unsupported_identifier_filters(): void
    {
        $question = 'Сравни все акты организации с проектом 100';
        $rejected = [];
        foreach ([['gt', 100], ['gte', 101], ['lt', 100], ['lte', 99], ['gt', 99], ['gte', 100],
            ['lt', 101], ['lte', 100], ['contains', '100'], ['unsupported', 100]] as [$operator, $value]) {
            $sections = [['scope' => 'organization', 'project_id' => null], ['scope' => 'project', 'project_id' => 100,
                'filters' => [['field' => 'project_id', 'operator' => $operator, 'value' => $value]]]];
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                $rejected[] = false;
            } catch (AssistantReportScopeAmbiguous) {
                $rejected[] = true;
            }
        }
        self::assertSame(array_fill(0, 10, true), $rejected);
    }

    public function test_project_sections_keep_proven_consistent_identifier_filters(): void
    {
        $question = 'Сравни все акты организации с проектом 100';
        foreach ([['eq', 100], ['neq', 99], ['in', [52, 100]], ['is_not_null', null]] as [$operator, $value]) {
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act',
                'filters' => [['field' => 'project_id', 'operator' => $operator, 'value' => $value]]]]];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments));
        }
    }

    public function test_current_or_literal_project_does_not_bypass_a_named_comparison_lookup(): void
    {
        foreach (['выбранным проектом', 'проектом 52'] as $first) {
            $question = 'Сравни все акты организации с '.$first.' и проектом СевГУ';
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            $calls = [];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
                static function (array $arguments) use (&$calls): array {
                    $calls[] = $arguments;

                    return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
                }));
            self::assertSame([['query' => 'СевГУ', 'name_match' => 'exact', 'limit' => 2]], $calls);
        }
    }

    public function test_each_named_comparison_operand_requires_its_own_exact_lookup(): void
    {
        foreach (['проектом Альфа и проектом Бета', 'проектом «Альфа» и объектом «Бета»',
            'проектом Альфа, объектом Бета', 'проектом Альфа и по проекту Бета',
            'проектом Альфа и проектом Бета за прошлый месяц по статусам'] as $operands) {
            $question = 'Сравни все акты организации с '.$operands;
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 102, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            $calls = [];
            $search = static function (array $arguments) use (&$calls): array {
                $calls[] = $arguments;

                return ['status' => 'success', 'results' => match ($arguments['query']) {
                    'Альфа' => [['id' => 100]], 'Бета' => [['id' => 102]], default => [],
                }, 'result_window' => ['has_more' => false]];
            };
            $hasPeriod = str_contains($operands, 'за прошлый месяц');
            try {
                $result = AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search);
                self::assertFalse($hasPeriod, 'An unbound period was ignored');
                self::assertSame($arguments, $result, $question);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue($hasPeriod, 'An ordinary named comparison required clarification');
            }
            self::assertContains(['query' => 'Альфа', 'name_match' => 'exact', 'limit' => 2], $calls);
            self::assertContains(['query' => 'Бета', 'name_match' => 'exact', 'limit' => 2], $calls);
        }
    }

    public function test_multiple_project_names_preserve_full_literal_priority_and_exact_search_safety(): void
    {
        $question = 'Сравни все акты организации с проектом Альфа и проектом Бета';
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 104, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
        $calls = [];
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
            static function (array $arguments) use (&$calls): array {
                $calls[] = $arguments;

                return ['status' => 'success', 'results' => [['id' => 104]], 'result_window' => ['has_more' => false]];
            }));
        self::assertSame([['query' => 'Альфа и проектом Бета', 'name_match' => 'exact', 'limit' => 2]], $calls);
        foreach ([['status' => 'error'], ['status' => 'success', 'results' => [], 'result_window' => ['has_more' => true]],
            ['status' => 'success', 'results' => [['id' => 104], ['id' => 105]], 'result_window' => ['has_more' => false]]] as $unsafe) {
            $calls = [];
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
                    static function (array $arguments) use (&$calls, $unsafe): array {
                        $calls[] = $arguments;

                        return $unsafe;
                    });
                self::fail('An unsafe full-name result was split into independent projects');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertCount(1, $calls);
            }
        }
    }

    public function test_literal_coordination_inside_any_list_operand_keeps_its_project_identity(): void
    {
        foreach ([['Альфа и проектом Бета', 'Гамма'], ['Альфа', 'Бета и проектом Гамма']] as [$first, $second]) {
            $question = 'Сравни все акты организации с проектом '.$first.' и проектом '.$second;
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 102, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            $calls = [];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
                static function (array $arguments) use (&$calls, $first, $second): array {
                    $calls[] = $arguments['query'];

                    return ['status' => 'success', 'results' => $arguments['query'] === $first ? [['id' => 100]]
                        : ($arguments['query'] === $second ? [['id' => 102]] : []), 'result_window' => ['has_more' => false]];
                }));
            self::assertContains($first, $calls);
            self::assertContains($second, $calls);
        }
    }

    public function test_multiple_names_never_return_a_partial_project_selection(): void
    {
        foreach ([null, ['status' => 'error'], ['status' => 'success', 'results' => [], 'result_window' => ['has_more' => false]],
            ['status' => 'success', 'results' => [['id' => 102]], 'result_window' => ['has_more' => true]],
            ['status' => 'success', 'results' => [['id' => 102], ['id' => 103]], 'result_window' => ['has_more' => false]],
            ['status' => 'success', 'results' => [['id' => '102']], 'result_window' => ['has_more' => false]]] as $unsafe) {
            $question = 'Сравни все акты организации с проектом «Альфа» и проектом «Бета»';
            $calls = [];
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                    ['sections' => [['scope' => 'organization', 'project_id' => null], ['scope' => 'project', 'project_id' => 100]]],
                    static function (array $arguments) use (&$calls, $unsafe): mixed {
                        $calls[] = $arguments;

                        return $arguments['query'] === 'Альфа'
                            ? ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]] : $unsafe;
                    });
                self::fail('A comparison with an unproven second project was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertSame(['Альфа', 'Бета'], array_column($calls, 'query'));
            }
        }
    }

    public function test_named_lists_combine_current_numeric_and_duplicate_project_identities(): void
    {
        foreach (['выбранным проектом и проектом 104 и проектом «Альфа» и проектом «Бета»',
            'проектом 104 и проектом «Альфа» и проектом «Бета» и выбранным проектом'] as $operands) {
            $question = 'Сравни все акты организации с '.$operands;
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 104, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            $calls = [];
            self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
                static function (array $arguments) use (&$calls): array {
                    $calls[] = $arguments;

                    return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
                }));
            self::assertSame(['Альфа', 'Бета'], array_column($calls, 'query'));
        }
    }

    public function test_names_with_comparison_words_do_not_hide_the_comparison_command(): void
    {
        $search = static fn (array $arguments): array => ['status' => 'success', 'results' => match ($arguments['query']) {
            'Сверка' => [['id' => 100]], 'Сравни' => [['id' => 102]], default => [],
        }, 'result_window' => ['has_more' => false]];
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract'], ['scope' => 'project', 'project_id' => 102, 'domain' => 'contracts', 'entity_type' => 'contract']]];
        $question = 'Покажи все акты организации и отдельно договоры по проекту Сверка и проекту Сравни';
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search));
        $question = 'Сравни все акты организации с проектом Сверка и проектом Сравни';
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments, $search);
    }

    public function test_overlapping_named_operands_mask_the_longest_confirmed_title_first(): void
    {
        $question = 'Покажи все акты организации и отдельно договоры по проекту Альфа и проекту Альфа Сравни';
        $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
            ['scope' => 'project', 'project_id' => 100, 'domain' => 'contracts', 'entity_type' => 'contract'], ['scope' => 'project', 'project_id' => 102, 'domain' => 'contracts', 'entity_type' => 'contract']]];
        self::assertSame($arguments, AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, $arguments,
            static fn (array $arguments): array => ['status' => 'success', 'results' => match ($arguments['query']) {
                'Альфа' => [['id' => 100]], 'Альфа Сравни' => [['id' => 102]], default => [],
            }, 'result_window' => ['has_more' => false]]));
    }

    public function test_each_numeric_project_list_item_accepts_its_own_identifier_marker(): void
    {
        foreach (['с проектами №52 и №100', 'с проектами #52 и #100', 'с проектами id52 и id100',
            'с проектами ID 52, id 100', 'с проектами №52, #100', 'с проектами 52 и №100',
            'с проектами №52 и проектом №100', 'с объектами №52 и №100',
            'с проектами №52, 100', 'с проектами 52, объектом #100'] as $list) {
            $question = 'Сравни все акты организации '.$list;
            $arguments = ['sections' => [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'],
                ['scope' => 'project', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]];
            $scope = AssistantRequestProjectScope::fromPlan($this->plan($question));
            $bound = null;
            try {
                $bound = $scope->bindReportScope($question, $arguments);
            } catch (AssistantReportScopeAmbiguous) {
            }
            self::assertSame($arguments, $bound, $question);
            self::assertSame(['project_ids' => [52, 100], 'pairwise' => true, 'subjects' => [
                '["organization","acts","performance_act"]' => true, '["project:52","acts","performance_act"]' => true,
                '["project:100","acts","performance_act"]' => true]], $scope->searchCoverageRequirements($question), $question);
            self::assertSame(['project_id' => 100], AssistantRequestProjectScope::bindSearchScope($question, ['project_id' => 100], 52, true), $question);
            array_pop($arguments['sections']);
            $rejected = false;
            try {
                $scope->bindReportScope($question, $arguments);
            } catch (AssistantReportScopeAmbiguous) {
                $rejected = true;
            }
            self::assertTrue($rejected, $question);
        }
    }

    public function test_mixed_report_requires_every_explicitly_compared_project(): void
    {
        $question = 'Сравни все акты организации с проектами 52 и 100';
        $rejected = [];
        foreach ([[52], [52, 52], [], [52, 52, 100]] as $ids) {
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act']];
            foreach ($ids as $id) {
                $sections[] = ['scope' => 'project', 'project_id' => $id, 'domain' => 'acts', 'entity_type' => 'performance_act'];
            }
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                $rejected[] = false;
            } catch (AssistantReportScopeAmbiguous) {
                $rejected[] = true;
            }
        }
        self::assertSame([true, true, true, true], $rejected);
        $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 52, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']];
        self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]));
        $sections[] = ['scope' => 'project', 'project_id' => 52, 'domain' => 'contracts', 'entity_type' => 'contract'];
        $this->expectException(AssistantReportScopeAmbiguous::class);
        AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
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
            try {
                AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections]);
                self::fail('An unrequested organization project filter was accepted');
            } catch (AssistantReportScopeAmbiguous) {
                self::assertTrue(true);
            }
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

    public function test_unquoted_comparison_resolves_grouping_but_clarifies_unbound_periods_after_empty_full_name(): void
    {
        foreach (['за октябрь', 'за октябрь 2026 года', 'по статусам', 'за октябрь по статусам и валютам', 'с группировкой по статусам',
            'по состоянию на вчера', 'с начала 2026 года', 'с 2026-10-01 по 2026-10-15', 'за 01.10.2026'] as $suffix) {
            $question = 'Сравни все акты организации с проектом СевГУ '.$suffix;
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']];
            $calls = [];
            $search = static function (array $arguments) use (&$calls): array {
                $calls[] = $arguments;

                return ['status' => 'success', 'results' => $arguments['query'] === 'СевГУ' ? [['id' => 100]] : [], 'result_window' => ['has_more' => false]];
            };
            $grouping = in_array($suffix, ['по статусам', 'с группировкой по статусам'], true);
            try {
                $result = AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question, ['sections' => $sections], $search);
                self::assertTrue($grouping, 'An unbound period was ignored: '.$suffix);
                self::assertSame(['sections' => $sections], $result);
            } catch (AssistantReportScopeAmbiguous) {
                self::assertFalse($grouping, 'Grouping unexpectedly required clarification: '.$suffix);
            }
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
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act'], ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']];
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
                    ['sections' => [['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]], static function (array $arguments) use (&$calls): array {
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
                    ['sections' => [['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']]], static function () use (&$calls, $result): mixed {
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
            $sections = [['scope' => 'organization', 'project_id' => null, 'domain' => 'acts', 'entity_type' => 'performance_act', 'filters' => [['field' => 'project_id', 'operator' => 'neq', 'value' => 52]]],
                ['scope' => 'project', 'project_id' => 100, 'domain' => 'acts', 'entity_type' => 'performance_act']];
            if (str_contains($exclusion, '53')) {
                $sections[0]['filters'][] = ['field' => 'project_id', 'operator' => 'neq', 'value' => 53];
            }
            self::assertSame(['sections' => $sections], AssistantRequestProjectScope::fromPlan($this->plan($question))->bindReportScope($question,
                ['sections' => $sections], static function (array $arguments): array {
                    self::assertSame('Север с корпусом Б', $arguments['query']);
                    self::assertSame('exact', $arguments['name_match']);

                    return ['status' => 'success', 'results' => [['id' => 52]], 'result_window' => ['has_more' => false]];
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

    public function test_search_coverage_distinguishes_independent_sections_using_the_same_confirmed_names(): void
    {
        foreach (['Покажи все акты организации, а также договоры проекта 100' => [100, false],
            'Покажи все акты организации, а также договоры проекта 100. Сравнивать их не нужно.' => [100, false],
            'Покажи все акты организации, а также договоры выбранного проекта' => [52, false],
            'Покажи договоры выбранного проекта, а также все акты организации' => [52, false],
            'Сравни все акты организации с договорами проекта 100' => [100, true]] as $question => [$projectId, $pairwise]) {
            self::assertTrue(AssistantRequestProjectScope::organizationWide($question), $question);
            $requirements = AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question);
            self::assertSame([$projectId], $requirements['project_ids']);
            self::assertSame($pairwise, $requirements['pairwise']);
            if (! $pairwise) {
                self::assertEqualsCanonicalizing(['["organization","acts","performance_act"]', '["project:'.$projectId.'","contracts","contract"]'], array_keys($requirements['subjects']));
            }
        }
        foreach (['Сверка', '«Сверка»'] as $name) {
            $question = 'Покажи все акты организации, а также договоры проекта '.$name;
            $calls = 0;
            $requirements = AssistantRequestProjectScope::fromPlan($this->plan($question))->searchCoverageRequirements($question,
                static function (array $arguments) use (&$calls): array {
                    $calls++;
                    self::assertSame('Сверка', $arguments['query']);
                    self::assertSame('exact', $arguments['name_match']);

                    return ['status' => 'success', 'results' => [['id' => 100]], 'result_window' => ['has_more' => false]];
                });
            self::assertSame([100], $requirements['project_ids']);
            self::assertFalse($requirements['pairwise']);
            self::assertEqualsCanonicalizing(['["organization","acts","performance_act"]', '["project:100","contracts","contract"]'], array_keys($requirements['subjects']));
            self::assertSame(1, $calls);
        }
    }

    private function plan(string $question): array
    {
        return ['request' => ['message' => $question, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }
}
