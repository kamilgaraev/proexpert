<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\RequestUnderstanding;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class AssistantRequestUnderstandingResolverTest extends TestCase
{
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
