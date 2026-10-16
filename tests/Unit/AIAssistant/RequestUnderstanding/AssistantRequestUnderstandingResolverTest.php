<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantRequestUnderstandingResolverTest extends TestCase
{
    public function test_excel_and_csv_requests_are_explicit_files_and_respect_no_file_constraints(): void
    {
        $resolver = new AssistantRequestUnderstandingResolver;
        foreach (['Сделай Excel: договоры по подрядчикам с суммами и статусами' => 'xlsx',
            'Выгрузи CSV с просроченными платежами' => 'csv', 'Сформируй таблицу в XLSX по проектам' => 'xlsx',
            'Сделай Excel по договорам, без PDF' => 'xlsx', 'Из Excel сделай CSV с платежами' => 'csv',
            'Сделай PDF по проектам, не Excel' => 'pdf'] as $query => $format) {
            $understanding = $resolver->resolve($query, []);
            self::assertSame('generate_report', $understanding->primaryIntent);
            self::assertSame($format, $understanding->outputFormat);
        }
        $negative = $resolver->resolve('Объясни Excel простым текстом, без файлов и без отчётов', []);
        self::assertNotSame('generate_report', $negative->primaryIntent);
        self::assertTrue($negative->blocksFileGeneration());
        $understanding = $resolver->resolve('Сделай Excel по договорам, без PDF', []);
        $policy = new \App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
        self::assertTrue($policy->canExecuteTool('generate_custom_report', $understanding, false, ['format' => 'xlsx'])->allowed);
        self::assertFalse($policy->canExecuteTool('generate_custom_report', $understanding, false, ['format' => 'pdf'])->allowed);
        self::assertFalse($policy->canExecuteTool('generate_operational_pdf_report', $understanding)->allowed);
    }

    public function test_no_pdf_preserves_explicit_spreadsheet_export_with_summary_analysis_or_knowledge_markers(): void
    {
        $resolver = new AssistantRequestUnderstandingResolver;
        foreach (['Подготовь сводку в Excel без PDF' => 'xlsx',
            'Проанализируй платежи и выгрузи CSV, не PDF' => 'csv',
            'Найди факты из базы и сделай Excel, без PDF' => 'xlsx'] as $query => $format) {
            $result = $resolver->resolve($query);
            self::assertSame('generate_report', $result->primaryIntent, $query);
            self::assertSame($format, $result->outputFormat, $query);
            self::assertContains('no_pdf', $result->constraints);
            self::assertFalse($result->blocksFileGeneration());
            self::assertTrue(\App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantCustomReportIntent::matches($query, $result));
        }
        foreach (['Подготовь сводку в Excel без PDF. Только текст.',
            'Проанализируй платежи в CSV, без PDF и без файлов.',
            'Найди факты в Excel, без PDF и без отчётов.'] as $query) {
            $result = $resolver->resolve($query);
            self::assertNotSame('generate_report', $result->primaryIntent, $query);
            self::assertTrue($result->blocksFileGeneration(), $query);
        }
    }

    public function test_negative_creation_applies_to_its_own_format_instead_of_all_files(): void
    {
        $resolver = new AssistantRequestUnderstandingResolver;
        foreach (['Не делай PDF, сделай Excel по платежам' => 'xlsx',
            'Не формируй PDF, сделай CSV по проектам' => 'csv',
            'Не создавай ПДФ, подготовь сводку в Excel' => 'xlsx',
            'Не выгружай PDF, нужен CSV по платежам' => 'csv',
            'Сделай CSV по платежам, не генерируй PDF' => 'csv',
            'Не делай файл PDF, сделай Excel по платежам' => 'xlsx',
            'Не формируй PDF-файл, нужен CSV по проектам' => 'csv',
            'Без файла PDF, подготовь сводку в Excel' => 'xlsx',
            'Не нужен файл PDF, нужен CSV по платежам' => 'csv',
            'Не делай отчет в PDF, сделай Excel по договорам' => 'xlsx'] as $query => $format) {
            $result = $resolver->resolve($query);
            self::assertSame('generate_report', $result->primaryIntent, $query);
            self::assertSame($format, $result->outputFormat, $query);
            self::assertContains('no_pdf', $result->constraints);
            self::assertNotContains('no_file', $result->constraints);
            self::assertNotContains('no_report', $result->constraints);
            self::assertFalse($result->blocksFileGeneration());
        }
        foreach (['Не делай PDF, файл или отчет. Сделай сводку текстом.',
            'Не формируй файлы, сделай сводку по платежам в Excel',
            'Не создавай отчет, покажи данные в CSV',
            'Не делай PDF, файл или отчет. Сделай сводку в Excel.'] as $query) {
            self::assertTrue($resolver->resolve($query)->blocksFileGeneration(), $query);
        }
    }

    use UsesAssistantUnitTranslations;

    public function test_settings_toggles_require_confirmation(): void
    {
        foreach (['Отключи уведомления администратора о просроченном авансовом отчёте',
            'Отключи мне уведомления администратора о просроченном авансовом отчёте',
            'Включи нам обязательное указание проекта для подотчётного расхода',
            'Включи обязательное указание проекта для подотчётного расхода'] as $query) {
            $result = (new AssistantRequestUnderstandingResolver)->resolve($query);
            self::assertSame('update', $result->primaryIntent);
            self::assertSame('requires_confirmation', $result->actionPolicy);
        }
        self::assertSame('requires_confirmation', (new AssistantRequestUnderstandingResolver)->resolve('Замени лимит подотчётных средств на 10000')->actionPolicy);
        self::assertSame('requires_confirmation', (new AssistantRequestUnderstandingResolver)->resolve('Поставь лимит 10000 в таблице настроек')->actionPolicy);
    }

    public function test_make_project_summary_remains_read_only(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve('Сделай сводку по проектам');

        self::assertSame('summarize', $result->primaryIntent);
        self::assertSame('read_only', $result->actionPolicy);
        foreach (['Сделай список договоров проекта', 'Сделай подборку просроченных задач',
            'Сделай таблицу договоров проекта', 'Сделай перечень договоров', 'Сделай выборку просроченных задач',
            'Примени фильтр по активным договорам', 'Примените сортировку по сроку окончания',
            'Поставь даты окончания договоров в отдельную колонку', 'Выставь сроки окончания договоров отдельной колонкой',
            'Поставь в таблицу даты окончания договоров'] as $query) {
            self::assertSame('read_only', (new AssistantRequestUnderstandingResolver)->resolve($query)->actionPolicy);
        }
    }

    public function test_compound_mutation_requires_confirmation_without_overriding_no_actions(): void
    {
        foreach (['Покажи инструкцию: установить лимит подотчётных средств',
            'Покажи инструкцию: поставь лимит подотчётных средств',
            'Что означает команда: поставь лимит 10000?',
            "Покажи инструкцию:\nпоставь лимит подотчётных средств",
            "Пример:\nотключи уведомления администратора",
            'Вопрос: установить лимит можно только администратору?'] as $query) {
            self::assertSame('read_only', (new AssistantRequestUnderstandingResolver)->resolve($query)->actionPolicy, $query);
        }
        self::assertSame('requires_confirmation', (new AssistantRequestUnderstandingResolver)->resolve('Поставь лимит 10000 и покажи настройки таблицей')->actionPolicy);
        self::assertSame('requires_confirmation', (new AssistantRequestUnderstandingResolver)->resolve('Покажи инструкцию и установи лимит подотчётных средств 10000')->actionPolicy);
        foreach (['Покажи лимит подотчётных средств и замени его на 10000',
            'Покажи лимит подотчётных средств; замени его на 10000',
            'Покажи лимит подотчётных средств: замени его на 10000',
            'Покажи лимит подотчётных средств — замени его на 10000',
            'Покажи лимит подотчётных средств - замени его на 10000',
            'Какой лимит подотчётных средств? Увеличь его до 10000',
            'Покажи настройки и отключи мне уведомления администратора'] as $query) {
            $result = (new AssistantRequestUnderstandingResolver)->resolve($query);
            self::assertSame('update', $result->primaryIntent, $query);
            self::assertSame('requires_confirmation', $result->actionPolicy, $query);
            self::assertSame('read_only', (new AssistantRequestUnderstandingResolver)->resolve($query.'. Ничего не меняй.')->actionPolicy);
        }
        foreach (['Покажи уведомления администратора о просроченном авансовом отчёте и отключи их',
            'Покажи уведомления администратора о просроченном авансовом отчёте и включи их',
            'Покажи обязательное указание проекта для подотчётного расхода и отключи это'] as $query) {
            self::assertSame('update', (new AssistantRequestUnderstandingResolver)->resolve($query)->primaryIntent, $query);
            self::assertSame('requires_confirmation', (new AssistantRequestUnderstandingResolver)->resolve($query)->actionPolicy, $query);
        }
    }

    public function test_mutation_commands_share_classification_with_project_read_guards(): void
    {
        foreach ([
            'create' => ['создай', 'добавь', 'создайте', 'добавьте'],
            'update' => ['измени', 'обнови', 'назначь', 'перенеси'],
            'delete' => ['удали', 'удалите'],
            'approve' => ['утверди', 'согласуй', 'одобри', 'подтверди платеж'],
            'send' => ['отправь уведомление', 'уведоми'],
        ] as $intent => $commands) {
            foreach ($commands as $command) {
                foreach (['', 'Пожалуйста, ', 'Прошу: '] as $prefix) {
                    self::assertSame($intent, (new AssistantRequestUnderstandingResolver)->resolve($prefix.$command.' запись')->primaryIntent);
                    $scheduleQuery = $prefix.$command.' задачи нашего объекта, задерживающие работы';
                    self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectScheduleImpactReader::matches($scheduleQuery));
                    $paymentQuery = $prefix.$command.' неоплаченные счета нашего объекта с превышенным бюджетным лимитом';
                    self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectPaymentReader::matches($paymentQuery));
                    self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectScheduleImpactReader::matches(
                        'Покажи задачи нашего объекта, задерживающие работы, и '.$command.' запись'));
                    self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantProjectPaymentReader::matches(
                        'Покажи неоплаченные счета нашего объекта и '.$command.' запись'));
                }
            }
        }
        self::assertNull(\App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent::type('Какие задачи задерживают этапы?'));
        foreach (['Обновите сроки', 'Согласуйте документы', 'Отправь счета', 'Обновить сроки', 'Перенести задачи'] as $command) {
            self::assertTrue(\App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent::containsRequest($command));
        }
    }

    public function test_negative_pdf_report_request_is_text_rag_read_only(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'По проекту «Кирпичный дом "Лесной двор"» перечисли 5 фактов из базы знаний. Только текст. Не создавай PDF, файл или отчет.',
            []
        );

        $this->assertSame('search_knowledge', $result->primaryIntent);
        $this->assertSame('text', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_pdf'));
        $this->assertTrue($result->hasConstraint('no_file'));
        $this->assertTrue($result->hasConstraint('no_report'));
        $this->assertTrue($result->hasConstraint('text_only'));
        $this->assertTrue($result->hasConstraint('sources_required'));
        $this->assertContains('project', $result->requestedEntities);
        $this->assertGreaterThanOrEqual(0.7, $result->confidence);
        $this->assertNotSame([], $result->evidence);
    }

    public function test_knowledge_facts_without_actions_stays_read_only(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Найди в базе знаний 3-5 фактов по проекту. Не выполняй действий, не создавай отчет, просто перечисли факты.',
            []
        );

        $this->assertSame('search_knowledge', $result->primaryIntent);
        $this->assertSame('text', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertTrue($result->hasConstraint('no_report'));
        $this->assertTrue($result->hasConstraint('sources_required'));
        $this->assertContains('project', $result->requestedEntities);
    }

    public function test_explicit_pdf_report_request_allows_file_generation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Сформируй PDF-отчет по проекту «Кирпичный дом "Лесной двор"».',
            []
        );

        $this->assertSame('generate_report', $result->primaryIntent);
        $this->assertSame('pdf', $result->outputFormat);
        $this->assertSame('allow_file_generation', $result->actionPolicy);
        $this->assertFalse($result->hasConstraint('no_pdf'));
        $this->assertFalse($result->hasConstraint('no_file'));
        $this->assertContains('project', $result->requestedEntities);
    }

    public function test_send_report_wording_allows_file_generation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Отправь отчет по выполненным работам.',
            []
        );

        $this->assertSame('generate_report', $result->primaryIntent);
        $this->assertSame('allow_file_generation', $result->actionPolicy);
        $this->assertFalse($result->hasConstraint('no_report'));
        $this->assertFalse($result->hasConstraint('no_file'));
    }

    public function test_human_file_request_allows_file_generation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Сделай мне файл по Лесному двору, чтобы я мог быстро показать руководителю: текущее состояние, деньги, риски и ближайшие шаги.',
            []
        );

        $this->assertSame('generate_report', $result->primaryIntent);
        $this->assertSame('file', $result->outputFormat);
        $this->assertSame('allow_file_generation', $result->actionPolicy);
        $this->assertFalse($result->hasConstraint('no_file'));
        $this->assertFalse($result->hasConstraint('no_report'));
    }

    public function test_project_summary_without_changes_and_files_is_read_only(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Покажи краткую сводку по проекту без изменений и без файлов.',
            []
        );

        $this->assertSame('summarize', $result->primaryIntent);
        $this->assertSame('text', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_file'));
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertContains('project', $result->requestedEntities);
    }

    public function test_strict_json_without_actions_and_navigation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Ответь строго JSON без markdown. Без действий и без навигации.',
            []
        );

        $this->assertSame('json', $result->outputFormat);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('json_only'));
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertTrue($result->hasConstraint('no_navigation'));
    }

    public function test_payment_review_does_not_allow_mutation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Покажи, какие платежи требуют согласования, но ничего не утверждай.',
            []
        );

        $this->assertSame('analyze', $result->primaryIntent);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint('no_actions'));
        $this->assertContains('payment', $result->requestedEntities);
    }

    public function test_approval_request_requires_confirmation(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve('Утверди платеж', []);

        $this->assertSame('approve', $result->primaryIntent);
        $this->assertSame('requires_confirmation', $result->actionPolicy);
        $this->assertContains('payment', $result->requestedEntities);
    }

    public function test_navigation_is_allowed_only_for_explicit_navigation_request(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve(
            'Открой проект «Кирпичный дом "Лесной двор"».',
            []
        );

        $this->assertSame('navigate', $result->primaryIntent);
        $this->assertSame('allow_navigation', $result->actionPolicy);
        $this->assertContains('project', $result->requestedEntities);
    }

    #[DataProvider('sectionNavigationProvider')]
    public function test_bare_registered_sections_are_navigation_without_entity_lookup(string $message, string $domain): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve($message, [
            'source_module' => 'ai-assistant', 'source_route' => '/dashboard',
            'ui_state' => ['pathname' => '/dashboard', 'assistant_path' => '/assistant'],
        ]);

        $this->assertSame('navigate', $result->primaryIntent);
        $this->assertContains(['type' => 'section_navigation', 'value' => $domain], $result->evidence);
    }

    public static function sectionNavigationProvider(): array
    {
        return [
            ['Где сметы?', 'estimates'], ['Как найти раздел проектов?', 'projects'],
            ['Куда перейти в склад?', 'warehouse'], ['Где находится раздел платежей?', 'payments'],
            ['Открой договоры', 'contracts'], ['Где единицы измерения?', 'measurement_units'],
        ];
    }

    public function test_background_project_context_does_not_change_bare_registered_section_intent(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve('Где сметы?', [
            'source_module' => 'ai-assistant', 'source_route' => null,
            'entity_refs' => [['type' => 'project', 'id' => 42, 'label' => 'Проект']],
            'filters' => [], 'period' => null, 'ui_state' => ['assistant_path' => '/ai-assistant/chat'],
        ]);

        $this->assertSame('navigate', $result->primaryIntent);
        $this->assertContains(['type' => 'section_navigation', 'value' => 'estimates'], $result->evidence);
    }

    #[DataProvider('nonSectionNavigationProvider')]
    public function test_entity_scopes_and_semantic_requests_are_not_bare_section_navigation(string $message, array $context): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve($message, $context);

        $this->assertNotContains('section_navigation', array_column($result->evidence, 'type'));
    }

    public static function nonSectionNavigationProvider(): array
    {
        return [
            ['Где смета «Северный дом»?', []], ['Где смета №5?', []], ['Где проект Альфа?', []],
            ['Найди сметы', []], ['Где сметы за май?', []], ['Где сметы по проекту?', []],
            ['Где сметы? Без навигации.', []], ['Где сметы?', ['filters' => ['status' => 'approved']]],
            ['Где проекты?', ['period' => ['from' => '2026-01-01']]],
            ['Где сметы?', ['entity_refs' => [['type' => 'estimate', 'id' => 5]]]],
            ['Где сметы по проекту?', ['entity_refs' => [['type' => 'project', 'id' => 42]]]],
            ['Где сметы проекта Северный дом?', ['entity_refs' => [['type' => 'project', 'id' => 42]]]],
            ['Где сметы?', ['entity_refs' => [['type' => 'project', 'id' => 42]], 'period' => ['from' => '2026-01-01']]],
            ['Где сметы?', ['entity_refs' => [['type' => 'project', 'id' => 42]], 'filters' => ['status' => 'draft']]],
            ['Где сметы?', ['entity_refs' => [['type' => 'project', 'id' => 42], ['type' => 'estimate', 'id' => 5]]]],
            ['Где сметы?', ['selected_estimate_id' => 5]],
            ['Где сметы?', ['ui_state' => ['selected_estimate' => ['estimate_id' => 5]]]],
            ['Где договоры с заказчиком?', []],
        ];
    }

    #[DataProvider('negativeReportProvider')]
    public function test_negative_report_and_file_words_do_not_create_generation_intent(string $message, string $constraint): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve($message, []);

        $this->assertNotSame('generate_report', $result->primaryIntent);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertTrue($result->hasConstraint($constraint));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function negativeReportProvider(): array
    {
        return [
            'negative report' => ['Без отчета расскажи, какие риски по проекту.', 'no_report'],
            'negative file' => ['Не нужен файл, просто напиши текстом.', 'no_file'],
        ];
    }

    public function test_estimate_followup_preserves_domain_and_entity(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve('Какая прибыль и итоговая стоимость?', ['entity_references' => [['type' => 'estimate', 'id' => 7]]]);
        $this->assertSame('question', $result->primaryIntent);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertContains('estimate', $result->requestedEntities);
        $this->assertContains(['type' => 'primary_domain', 'value' => 'estimates'], $result->evidence);
    }

    public function test_explicit_domain_change_overrides_estimate_context(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve('Найди сотрудников', ['selected_estimate_id' => 7, 'last_capability' => 'estimates']);
        $this->assertSame('find', $result->primaryIntent);
        $this->assertNotContains('estimate', $result->requestedEntities);
        $this->assertContains(['type' => 'primary_domain', 'value' => 'people'], $result->evidence);
    }

    public function test_measurement_question_cannot_become_write_request(): void
    {
        $result = (new AssistantRequestUnderstandingResolver)->resolve('Как создать единицу измерения?');
        $this->assertSame('question', $result->primaryIntent);
        $this->assertSame('read_only', $result->actionPolicy);
        $this->assertContains('measurement_unit', $result->requestedEntities);
    }
}
