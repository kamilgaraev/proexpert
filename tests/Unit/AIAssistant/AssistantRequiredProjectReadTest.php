<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequiredProjectRead;
use PHPUnit\Framework\TestCase;

final class AssistantRequiredProjectReadTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_operational_director_reads_bind_project_and_do_not_replace_mixed_answers(): void
    {
        $queries = [
            'get_project_journal_readiness' => 'Что у нас с журналами работ по объекту: есть ли записи, которые ещё не подписаны, и что нужно закрыть перед сдачей?',
            'get_project_safety_summary' => 'Есть ли на нашем объекте открытые нарушения охраны труда или происшествия? Что самое срочное и кто должен этим заняться?',
            'get_project_change_readiness' => 'Какие вопросы проектировщикам по нашему объекту ещё без ответа и какие изменения ждут согласования? Выдели то, что может задержать работы.',
            'get_project_design_readiness' => 'Какие проектные комплекты на нашем объекте ещё не выпущены и что мешает выпуску: замечания, листы или согласования?',
        ];
        foreach ($queries as $tool => $query) {
            $plan = $this->plan($query);
            self::assertSame(['name' => $tool, 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
            self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($plan, $tool, ['project_id' => 999]));
            self::assertNotNull(AssistantRequiredProjectRead::decisionReader($tool, $query));
            foreach (array_diff(array_keys($queries), [$tool]) as $other) { self::assertNull(AssistantRequiredProjectRead::decisionReader($other, $query)); }
        }
        foreach (['Что по журналам работ нашего объекта и сколько затрат за сентябрь?',
            'Что по журналам работ нашего объекта и какие расходы за сентябрь?',
            'Какие проектные комплекты нашего объекта не выпущены и какова прибыль?',
            'Какие изменения нашего объекта ждут согласования и какая у них смета?',
            'Что требует внимания по журналам работ и охране труда нашего объекта?',
            'Что по журналам работ нашего объекта и какие происшествия?',
            'Какие нарушения охраны труда нашего объекта и что ждём от поставщиков?',
            'Какие открытые нарушения на нашем объекте и какие действующие договоры?',
            'Какие происшествия на нашем объекте и какие действующие договоры?',
            'Какие проектные комплекты нашего объекта готовы и какие счета оплачены?',
            'Покажи изменения на согласовании и журнал работ нашего объекта.',
            'Какие нарушения охраны труда нашего объекта и какие комплекты ПИР готовы?',
            'Согласуй запись журнала работ нашего объекта.'] as $mixed) {
            self::assertNull(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOperationalReaderRegistry::intent($mixed));
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($mixed)));
            foreach (array_keys($queries) as $tool) { self::assertNull(AssistantRequiredProjectRead::decisionReader($tool, $mixed)); }
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_contract_roster', $mixed));
        }
    }

    public function test_handover_summary_binds_selected_project_and_preserves_other_requests(): void
    {
        $query = 'Что у нас по приёмке и передаче нашего объекта: какие зоны уже приняты, какие ещё в работе и что должно быть следующим шагом?';
        $plan = $this->plan($query);
        self::assertSame(['name' => 'get_project_handover_summary', 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
        self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($plan, 'get_project_handover_summary', ['project_id' => 999]));
        self::assertSame(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectHandoverReader::class, AssistantRequiredProjectRead::decisionReader('get_project_handover_summary', $query));
        foreach (['Какие зоны нашего объекта приняты и сколько затрат за сентябрь?', 'Покажи приёмку нашего проекта и его график.',
            'Прими зону нашего объекта.', 'Что по приёмке и передаче нашего объекта и сколько оплатить?',
            'Что по приёмке нашего объекта и какие действующие договоры?', 'Что по приёмке нашего объекта и какие счета не оплачены?'] as $mixed) {
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_handover_summary', $mixed));
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($mixed)));
        }
    }

    public function test_active_contract_meeting_roster_is_scoped_and_does_not_replace_money_questions(): void
    {
        $plan = $this->plan('Какие действующие договоры и подрядчики у нашего объекта? Покажи номера и плановые сроки окончания, чтобы я мог подготовить встречу с ними.');
        self::assertSame(['name' => 'get_project_contract_roster', 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
        self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($plan, 'get_project_contract_roster', ['project_id' => 999]));
        self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_contract_roster', 'Какие действующие договоры нашего проекта и сколько по ним надо оплатить?'));
        foreach (['Какие действующие договоры нашего проекта и сколько затрат за сентябрь?',
            'Какие действующие договоры нашего проекта и какая по ним выручка?'] as $mixed) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($mixed)));
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_contract_roster', $mixed));
        }
    }

    public function test_machinery_summary_binds_current_project_and_is_terminal_read_only(): void
    {
        $plan = $this->plan('Какая техника сейчас закреплена за нашим объектом и есть ли среди неё простаивающая или ожидающая ремонта? Что мне нужно решить в первую очередь?');
        self::assertSame(['name' => 'get_project_machinery_summary', 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
        self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($plan, 'get_project_machinery_summary', ['project_id' => 999]));
        self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_machinery_summary', 'Какая техника закреплена за нашим объектом и сколько потрачено за сентябрь?'));
        $mixed = 'Какая техника простаивает в нашем проекте и какие счета не оплачены?';
        self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($mixed)));
        self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_machinery_summary', $mixed));
    }

    public function test_director_compound_finance_and_schedule_impact_bind_verified_scope(): void
    {
        $finance = $this->plan('Теперь по деньгам: какой бюджет у нашего объекта, сколько уже потрачено за сентябрь 2026 года и можем ли мы говорить о перерасходе?');
        self::assertSame(['name' => 'get_project_finance_overview', 'arguments' => ['project_id' => 52, 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'currency' => null]], AssistantRequiredProjectRead::fromPlan($finance));
        self::assertSame(52, AssistantRequiredProjectRead::bindArguments($finance, 'get_project_finance_overview', ['project_id' => 999])['project_id']);
        $impact = $this->plan('Какая из просроченных задач сильнее всего влияет на общий срок проекта? Покажи почему так и что надо разблокировать.');
        self::assertSame(['name' => 'analyze_project_schedule_impact', 'arguments' => ['project_id' => 52, 'focus' => 'overdue_impact']], AssistantRequiredProjectRead::fromPlan($impact));
        self::assertSame(['project_id' => 52, 'focus' => 'overdue_impact'], AssistantRequiredProjectRead::bindArguments($impact, 'analyze_project_schedule_impact', ['project_id' => 999, 'focus' => 'dependencies']));
        self::assertSame('critical_path', AssistantRequiredProjectRead::fromPlan($this->plan('Какой критический путь нашего проекта?'))['arguments']['focus']);
        self::assertSame('dependencies', AssistantRequiredProjectRead::fromPlan($this->plan('Покажи зависимости задач нашего проекта.'))['arguments']['focus']);
        $people = $this->plan('Кто работал на нашем объекте за сентябрь 2026 года и сколько часов учтено? Есть ли табели, которые ещё нужно согласовать?');
        self::assertSame(['name' => 'get_project_workforce_summary', 'arguments' => ['project_id' => 52, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']],
            AssistantRequiredProjectRead::fromPlan($people));
        self::assertSame(52, AssistantRequiredProjectRead::bindArguments($people, 'get_project_workforce_summary', ['project_id' => 999])['project_id']);
        $payment = $this->plan('Какие счета по нашему объекту ещё не оплачены, сколько по каждому и что мне проверить перед оплатой?');
        self::assertSame(['name' => 'get_project_payment_readiness', 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($payment));
        self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($payment, 'get_project_payment_readiness', ['project_id' => 999]));
        self::assertSame('procurement', AssistantRequiredProjectRead::fromPlan($this->plan('Что со снабжением нашего объекта: какие поставки и заявки требуют решения сегодня, что может помешать ближайшим работам?'))['arguments']['focus']);
        self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan('Измени зависимости задач нашего проекта.')));
        foreach (['Какие задачи влияют на сроки нашего проекта и сколько потрачено за сентябрь 2026?',
            'Какой бюджет нашего проекта, сколько потрачено за сентябрь 2026 и какие задачи влияют на сроки?'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
            self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectFinanceOverview::matches($query));
            self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectScheduleImpactReader::matches($query));
        }
    }

    public function test_manager_priorities_use_a_single_current_project_attention_read(): void
    {
        $query = 'Я руководитель проекта. Что на нашем объекте сейчас требует моего внимания в первую очередь? Назови три главных проблемы, приведи факты и скажи, что делать дальше.';
        self::assertSame(['name' => 'get_project_attention', 'arguments' => ['project_id' => 52, 'focus' => 'all', 'limit' => 3]],
            AssistantRequiredProjectRead::fromPlan($this->plan($query)));
    }

    public function test_final_decision_reader_cannot_replace_a_compound_answer_with_one_section(): void
    {
        foreach (['Какие задачи влияют на сроки нашего проекта и сколько потрачено за сентябрь 2026?',
            'Какой бюджет нашего проекта, сколько потрачено за сентябрь 2026 и какие задачи влияют на сроки?',
            'Кто работал в нашем проекте за сентябрь 2026 и сколько потрачено?',
            'Какие счета нашего проекта не оплачены и сколько расходов за сентябрь 2026?',
            'Кто работал в нашем проекте за сентябрь 2026 и какие документы готовы?'] as $query) {
            foreach (['get_project_finance_overview', 'analyze_project_schedule_impact', 'get_project_workforce_summary', 'get_project_payment_readiness'] as $tool) {
                self::assertNull(AssistantRequiredProjectRead::decisionReader($tool, $query));
            }
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        }
        self::assertSame(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectWorkforceReader::class,
            AssistantRequiredProjectRead::decisionReader('get_project_workforce_summary', 'Кто работал на нашем объекте за сентябрь 2026 и сколько часов учтено?'));
    }

    public function test_manager_attention_preserves_specific_focus_and_does_not_capture_compound_money_requests(): void
    {
        self::assertSame('all', AssistantRequiredProjectRead::fromPlan($this->plan('Что в нашем объекте требует моего внимания? Дай три приоритета, ответственных и сроки следующего действия.'))['arguments']['focus']);
        foreach (['Что в графике нашего проекта требует внимания?' => 'schedule',
            'Какие главные проблемы снабжения нашего объекта?' => 'procurement',
            'Какие главные проблемы качества нашего проекта?' => 'quality'] as $query => $focus) {
            self::assertSame($focus, AssistantRequiredProjectRead::fromPlan($this->plan($query))['arguments']['focus']);
        }
        foreach (['Что требует внимания в нашем проекте и сколько потрачено за сентябрь 2026?',
            'Назови главные проблемы нашего объекта и его расходы.',
            'Какие главные проблемы качества и графика нашего проекта?'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        }
    }

    public function test_attention_remains_executable_in_the_terminal_read_only_phase(): void
    {
        $service = (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AIAssistantService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'isTerminalReadOnlyTool');
        self::assertTrue($method->invoke($service, 'get_project_attention'));
        self::assertTrue($method->invoke($service, 'get_project_finance_overview'));
        self::assertTrue($method->invoke($service, 'analyze_project_schedule_impact'));
        self::assertTrue($method->invoke($service, 'get_project_workforce_summary'));
        self::assertTrue($method->invoke($service, 'get_project_payment_readiness'));
        foreach (['get_document_readiness', 'get_project_machinery_summary', 'get_project_contract_roster'] as $tool) { self::assertTrue($method->invoke($service, $tool)); }
        self::assertFalse($method->invoke($service, 'approve_payment_request'));
    }

    public function test_unavailable_tool_missing_scope_and_compound_or_filtered_requests_are_not_forced(): void
    {
        $query = 'Есть на нашем объекте просроченные задачи? Сколько их?';
        self::assertNull(AssistantRequiredProjectRead::toolChoice($this->plan($query), [['name' => 'get_schedule_snapshot']]));
        foreach (['Сколько просроченных задач назначено Иванову на нашем объекте?', 'Сколько просроченных задач и договоров на нашем объекте?',
            'Покажи расходы и прогноз нашего объекта за сентябрь 2025 года.', 'Покажи расходы нашего объекта.',
            'Какие расходы нашего объекта по договору за сентябрь 2025 года?',
            'Покажи расходы на аренду техники нашего объекта за сентябрь 2025 года.',
            'Покажи расходы нашего объекта за сентябрь 2025 года в долларах.',
            'Покажи расходы нашего объекта за сентябрь 2025 года и сравни с августом.'] as $question) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($question)), $question);
        }
        self::assertNull(AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query, 'context' => ['project_id' => 52]]]));
        $plan = $this->plan($query);
        $plan['request']['context']['entity_refs'][] = ['type' => 'project', 'id' => 53];
        self::assertNull(AssistantRequiredProjectRead::fromPlan($plan));
    }

    private function plan(string $query): array
    {
        return ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
    }

    public function test_payment_readiness_understands_manager_document_wording_without_capturing_other_domains(): void
    {
        foreach ([
            'По нашему объекту какие платежные документы сейчас готовы к оплате, а какие задерживаются? Что проверить перед оплатой?',
            'Какие платёжные документы нашего объекта просрочены?',
            'Какие счета по нашему объекту готовы к оплате?',
        ] as $query) {
            self::assertSame(['name' => 'get_project_payment_readiness', 'arguments' => ['project_id' => 52]],
                AssistantRequiredProjectRead::fromPlan($this->plan($query)), $query);
            self::assertNotNull(AssistantRequiredProjectRead::decisionReader('get_project_payment_readiness', $query), $query);
        }
        foreach ([
            'Покажи все платежные документы нашего объекта.',
            'Какие платежные документы готовы к оплате и какие акты требуют согласования?',
            'Оплати готовые платежные документы нашего объекта.',
            'Какие платежные документы нашего объекта готовы к оплате и каков бюджет?',
        ] as $query) {
            self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectPaymentReader::matches($query), $query);
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_payment_readiness', $query), $query);
        }
    }

    public function test_missing_rd_sections_use_the_selected_project_and_design_readiness(): void
    {
        foreach (['напиши каких разделов РД не хватает', 'Каких разделов рабочей документации не хватает?',
            'Проверь комплектность РД нашего объекта.'] as $query) {
            self::assertSame(['name' => 'get_project_design_readiness', 'arguments' => ['project_id' => 52]],
                AssistantRequiredProjectRead::fromPlan($this->plan($query)), $query);
        }
        foreach (['Каких разделов РД не хватает и сколько осталось оплатить?', 'Измени состав разделов РД.',
            'Какие комплекты исполнительной документации не переданы?'] as $query) {
            self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantProjectDesignReader::matches($query), $query);
        }
    }

    public function test_report_catalog_answers_availability_without_replacing_actual_report_data(): void
    {
        $query = 'Какие доступные отчёты помогут мне контролировать принятый объём работ по объекту и сверять его с актами? Назови отчёты и где их открыть.';
        $plan = $this->plan($query);
        self::assertSame(['name' => 'get_project_report_catalog', 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
        self::assertNotNull(AssistantRequiredProjectRead::decisionReader('get_project_report_catalog', $query));
        self::assertContains('get_project_report_catalog', \App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface::names(['get_project_report_catalog'], $plan));
        foreach (['Какие доступные отчёты и сколько потрачено по нашему объекту?', 'Сформируй отчёт о принятом объёме нашего объекта.',
            'Что написано в доступных отчётах нашего проекта? Приведи выдержки.',
            'Какие доступные отчёты есть и покажи данные отчёта по принятому объёму нашего объекта?'] as $mixed) {
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_report_catalog', $mixed));
        }
        self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan('Какие доступные отчёты есть и покажи данные отчёта по принятому объёму нашего объекта?')));
    }

    public function test_commercial_executive_and_production_questions_bind_native_project_tools_and_keep_mixed_requests_unforced(): void
    {
        self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantOwnRolesReader::hasIntent('Есть ли по нашему объекту открытые CRM-сделки? Какие ближайшие действия и сроки по ним нужно проконтролировать?'));
        foreach (['Какие коммерческие предложения по нашему объекту ещё ждут нашего решения или ответа заказчика? Что нужно сделать следующим шагом?' => 'get_project_proposal_readiness',
            'Что у нас по исполнительной документации объекта: какие комплекты ещё не переданы заказчику и что мешает их передать? Есть ли передачи, по которым ждём ответ?' => 'get_project_executive_readiness',
            'Какие производственные наряды по нашему объекту уже представлены, но ещё не приняты? Кто отвечает за закрытие и что надо проверить перед принятием?' => 'get_project_production_readiness',
            'Есть ли по нашему объекту открытые CRM-сделки? Какие ближайшие действия и сроки по ним нужно проконтролировать?' => 'get_project_crm_readiness'] as $query => $name) {
            $plan = $this->plan($query);
            self::assertSame(['name' => $name, 'arguments' => ['project_id' => 52]], AssistantRequiredProjectRead::fromPlan($plan));
            self::assertNotNull(AssistantRequiredProjectRead::decisionReader($name, $query));
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_handover_summary', $query));
            self::assertSame(['project_id' => 52], AssistantRequiredProjectRead::bindArguments($plan, $name, ['project_id' => 99]));
            self::assertContains($name, \App\BusinessModules\Features\AIAssistant\Services\AssistantToolSurface::names([$name], $plan));
        }
        foreach (['Какие коммерческие предложения и исполнительные документы ждут решения по нашему объекту?',
            'Покажи производственные наряды и журналы работ нашего проекта.',
            'Какие исполнительные документы не переданы и сколько потрачено на нашем объекте?',
            'Что написано в исполнительной документации нашего объекта? Приведи выдержки и объясни правила.'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_executive_readiness', $query));
        }
        foreach (['Какие выигранные CRM-сделки по нашему объекту?',
            'Какие открытые CRM-сделки и просроченные задачи графика по нашему объекту?',
            'Покажи открытую CRM-сделку a28ea99b-14ce-43b9-b662-fd41ec4a3956 нашего проекта.'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
            self::assertNull(AssistantRequiredProjectRead::decisionReader('get_project_crm_readiness', $query));
        }
    }

    public function test_current_project_budget_reads_the_actual_budget_without_an_invented_currency_field(): void
    {
        $plan = $this->plan('Какой бюджет у нашего объекта? В какой валюте сумма?');
        $expected = ['name' => 'assistant_domain_read', 'arguments' => ['domain' => 'projects',
            'entity_type' => 'project', 'id' => 52, 'fields' => ['name', 'budget_amount'],
            'projection' => null, 'projection_offset' => 0, 'projection_limit' => 20]];
        self::assertSame($expected, AssistantRequiredProjectRead::fromPlan($plan));
        $catalog = new \App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog(
            \App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog::defaults());
        $reader = (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService::class))->newInstanceWithoutConstructor();
        $tool = new class($catalog, $reader) extends \App\BusinessModules\Features\AIAssistant\Actions\Domains\AssistantDomainTool {
            protected function operation(): string { return 'read'; }
        };
        (new \App\BusinessModules\Features\AIAssistant\Services\AssistantToolArgumentValidator)->validate(
            AssistantRequiredProjectRead::fromPlan($plan)['arguments'], $tool->getParametersSchema());
        self::assertSame($expected['arguments'], AssistantRequiredProjectRead::bindArguments($plan, 'assistant_domain_read',
            ['domain' => 'payments', 'entity_type' => 'payment_document', 'id' => 99, 'fields' => ['currency']]));
        foreach (['Какой бюджет нашего объекта в долларах?', 'Какой бюджет нашего объекта и сколько потрачено?',
            'Какой бюджет нашего объекта на бетон?'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        }
    }

    public function test_current_object_stock_uses_the_native_project_stock_scope(): void
    {
        $read = AssistantRequiredProjectRead::fromPlan($this->plan('Что сейчас есть на складе текущего объекта и сколько осталось?'));
        self::assertSame('get_project_material_stock', $read['name']);
        self::assertSame(['project_id' => 52, 'offset' => 0, 'limit' => 20], $read['arguments']);
        self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan('Сколько бетона осталось на складе текущего объекта?')));
        $stock = AssistantRequiredProjectRead::bindArguments($this->plan('Что сейчас есть на складе текущего объекта и сколько осталось?'),
            'get_project_material_stock', ['project_id' => 99, 'offset' => 20, 'limit' => 1]);
        self::assertSame(['project_id' => 52, 'offset' => 20, 'limit' => 20], $stock);
        $list = AssistantRequiredProjectRead::bindArguments($this->plan('Какие работы уже приняли на текущем объекте?'),
            'assistant_domain_search', ['project_id' => 99, 'cursor' => 'signed-continuation', 'predicate' => null]);
        self::assertSame('signed-continuation', $list['cursor']);
        self::assertSame(52, $list['project_id']);
        self::assertSame('confirmed', $list['predicate']);
    }

    public function test_current_project_list_reads_bind_entity_and_status_and_keep_compound_filters_unforced(): void
    {
        foreach ([['Какие работы уже приняли на текущем объекте?', 'works', 'completed_work', 'confirmed'],
            ['Что ещё ждём от поставщиков на этом объекте?', 'warehouse', 'project_material_delivery', 'supplier_waiting'],
            ['Какие незакрытые замечания есть по качеству на нашем объекте?', 'quality', 'quality_defect', 'unresolved']] as [$query, $domain, $type, $predicate]) {
            $read = AssistantRequiredProjectRead::fromPlan($this->plan($query));
            self::assertSame('assistant_domain_search', $read['name']);
            self::assertSame($domain, $read['arguments']['domain']);
            self::assertSame($type, $read['arguments']['entity_type']);
            self::assertSame($predicate, $read['arguments']['predicate']);
            self::assertSame(52, $read['arguments']['project_id']);
            self::assertSame('', $read['arguments']['query']);
        }
        foreach (['Какие работы приняли вчера на текущем объекте?', 'Какие незакрытые замечания Иванова есть на нашем объекте?',
            'Что ждём от поставщиков и какие работы приняли на этом объекте?',
            'Какие работы ещё не приняли на текущем объекте?', 'Какие не принятые работы на текущем объекте?',
            'Что уже не ждём от поставщиков на этом объекте?'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan($this->plan($query)));
        }
    }

    public function test_concrete_unit_price_read_binds_material_and_unit_without_requiring_a_project_selector(): void
    {
        foreach (['Найди в любой нашей смете, сколько стоит куб бетона.', 'Почём куб бетона у нас в сметах?', 'Покажи цена за м3 бетона в нашей смете'] as $query) {
            $read = AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query]]);
            self::assertSame('search_estimate_positions', $read['name']);
            self::assertSame('concrete_m3', $read['arguments']['price_subject']);
            self::assertSame('бетон', $read['arguments']['query']);
        }
        foreach (['Сколько стоит куб бетона и тонна арматуры?', 'Сколько стоит куб бетона В25?', 'Сколько стоит куб бетона в смете №17?', 'Сколько стоит куб бетона на текущем объекте?'] as $query) {
            self::assertNull(AssistantRequiredProjectRead::fromPlan(['request' => ['message' => $query]]));
        }
    }
}
