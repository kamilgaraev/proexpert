<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use PHPUnit\Framework\TestCase;

final class AssistantCapabilityRegistryTest extends TestCase
{
    public function test_estimate_position_fields_do_not_switch_to_the_unit_dictionary(): void
    {
        $query = 'Теперь выбери одну доступную смету текущего проекта и одну её позицию. Покажи название, количество, единицу измерения, цену за единицу и сохранённый итог. Укажи валюту и применённые коэффициенты, если они известны. Ничего не изменяй.';
        self::assertSame('estimates', (new AssistantCapabilityRegistry)->match($query)['id']);
    }

    use UsesAssistantUnitTranslations;

    public function test_overdue_tasks_select_schedule_instead_of_project_context(): void
    {
        $capability = (new AssistantCapabilityRegistry)->match('Есть на нашем объекте просроченные задачи? Сколько их?',
            ['source_module' => 'project-management', 'source_route' => '/projects/52']);
        self::assertSame('schedule', $capability['domain']);
        self::assertSame('schedule', (new AssistantCapabilityRegistry)->match('Сделай Excel по просроченным задачам всех проектов организации, которые ещё не завершены и не отменены: задача, проект, ответственный, плановая дата окончания и статус. На втором листе — количество таких задач по проектам.')['domain']);
    }

    public function test_generic_equipment_is_machinery_and_explicit_bim_equipment_remains_design(): void
    {
        $registry = new AssistantCapabilityRegistry;
        self::assertSame('machinery', $registry->match('Покажи оборудование')['domain']);
        self::assertSame('design', $registry->match('Сколько кондиционеров в IFC модели?')['domain']);
    }

    public function test_project_expenses_choose_finance_instead_of_project_status(): void
    {
        $registry = new AssistantCapabilityRegistry;
        foreach (['Покажи расходы нашего объекта за сентябрь 2025 года.', 'Сколько потратили на нашем объекте?', 'Затраты проекта за месяц'] as $query) {
            self::assertSame('finance', $registry->match($query, ['source_route' => '/projects/52'])['domain'], $query);
        }
    }

    public function test_every_catalog_domain_has_read_permissions_and_navigation(): void
    {
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $registry = new AssistantCapabilityRegistry($catalog);
        $capabilities = $registry->all();
        foreach ($catalog->all() as $domain => $definition) {
            $matching = array_values(array_filter($capabilities, static fn (array $capability): bool => $capability['domain'] === $domain));
            $this->assertCount(1, $matching, $domain);
            $this->assertSame($definition->permissions, $matching[0]['read_permissions'], $domain);
            $this->assertSame($definition->module, $matching[0]['module'], $domain);
            if ($definition->navigation === '') {
                $this->assertSame([], $matching[0]['actions'], $domain);

                continue;
            }
            $this->assertNotEmpty($matching[0]['actions'], $domain);
            foreach ($matching[0]['actions'] as $action) {
                $this->assertSame('navigate', $action['type'], $domain);
            }
        }
        $knowledge = array_values(array_filter($capabilities, static fn (array $capability): bool => $capability['id'] === 'knowledge'))[0];
        $this->assertSame('/knowledge-hub', $knowledge['actions'][0]['target']['route']);
        foreach (['projects', 'contracts', 'schedules', 'payments', 'reports'] as $id) {
            $this->assertContains($id, array_column($capabilities, 'id'));
        }
    }

    public function test_estimate_followup_uses_selection_references_or_last_capability(): void
    {
        $registry = new AssistantCapabilityRegistry;
        foreach ([['selected_estimate_id' => 7], ['last_capability' => 'estimates'], ['entity_references' => [['type' => 'estimate', 'id' => '7']]]] as $context) {
            $this->assertSame('estimates', $registry->match('Какая прибыль и итоговая стоимость?', $context)['domain']);
        }
    }

    public function test_explicit_new_domain_wins_over_stale_estimate_and_project_route(): void
    {
        $registry = new AssistantCapabilityRegistry;
        $context = ['selected_estimate_id' => 7, 'source_module' => 'project-management', 'source_route' => '/projects/5', 'last_capability' => 'estimates'];
        foreach (['Найди сотрудников' => 'people', 'Покажи оборудование' => 'machinery', 'Покажи безопасность' => 'safety', 'Найди смету' => 'estimates', 'Покажи смету проекта' => 'estimates'] as $query => $domain) {
            $this->assertSame($domain, $registry->match($query, $context)['domain'], $query);
        }
    }

    public function test_navigation_context_resolves_estimate_without_query_keywords(): void
    {
        $this->assertSame('estimates', (new AssistantCapabilityRegistry)->match('Покажи позиции', ['source_route' => '/estimates/7'])['domain']);
    }

    public function test_specific_project_domain_wins_without_selected_estimate_despite_stale_project_context(): void
    {
        $registry = new AssistantCapabilityRegistry;
        $context = ['source_module' => 'project-management', 'source_route' => '/projects/5',
            'ui_state' => ['assistant_path' => '/projects/5'], 'last_capability' => 'projects'];
        foreach (['Покажи смету проекта' => 'estimates', 'Найди договор проекта' => 'contracts', 'Покажи бюджет проекта' => 'budgeting'] as $query => $domain) {
            $this->assertSame($domain, $registry->match($query, $context, 'projects')['domain'], $query);
        }
    }

    public function test_measurement_units_has_one_capability_with_current_catalog_permissions(): void
    {
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $registry = new AssistantCapabilityRegistry($catalog);
        $units = array_values(array_filter($registry->all(), static fn (array $capability): bool => $capability['id'] === 'measurement_units'));
        $this->assertCount(1, $units);
        $this->assertSame($catalog->definition('measurement_units')->permissions, $units[0]['read_permissions']);
        $this->assertSame($catalog->definition('measurement_units')->module, $units[0]['module']);
        $this->assertSame('measurement_units', $registry->match('Найди единицу измерения')['domain']);
    }

    public function test_malformed_estimate_identifier_does_not_select_estimate(): void
    {
        $registry = new AssistantCapabilityRegistry;
        foreach ([['selected_estimate_id' => [7]], ['selected_estimate_id' => '7bad'], ['entity_references' => [['type' => 'estimate', 'id' => [7]]]]] as $context) {
            $this->assertFalse($registry->hasEstimateContext($context));
        }
    }

    public function test_domain_keywords_do_not_match_inside_unrelated_words(): void
    {
        $registry = new AssistantCapabilityRegistry;
        $this->assertSame('estimates', $registry->match('Проверь валидность источников', ['selected_estimate_id' => 7])['domain']);
        $this->assertNull($registry->match('валидность'));
    }

    public function test_natural_model_questions_resolve_design_despite_project_context(): void
    {
        $registry = new AssistantCapabilityRegistry;
        $context = ['source_module' => 'ai-assistant', 'entity_refs' => [['type' => 'project', 'id' => 52]]];
        foreach (['Какие перекрытия у гаражной?', 'Сколько колонн в модели гаража?', 'Покажи стены корпуса', 'Какая версия IFC сейчас?'] as $query) {
            $this->assertSame('design', $registry->match($query, $context)['domain'], $query);
        }
    }
}
