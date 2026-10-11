<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssistantTaskOrchestratorTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_classroom_calculation_ignores_business_context_and_action_controls(): void
    {
        $plan = $this->makeOrchestratorWithRealRegistry()->plan(
            'Для учебного примера вычисли 12 + 8 + 5. Это условные числа, не данные сметы или проекта.',
            ['goal' => 'generate_report', 'allow_actions' => true, 'context' => [
                'source_module' => 'estimates', 'entity_refs' => [['type' => 'estimate', 'id' => 9]],
            ]],
            ['organization_id' => 15, 'permissions_flat' => ['estimates.view', 'reports.view']]
        );

        self::assertNull($plan['capability']);
        self::assertSame([], $plan['request_understanding']['requested_entities']);
        self::assertSame('question', $plan['request_understanding']['primary_intent']);
        self::assertSame('read_only', $plan['request_understanding']['action_policy']);
        self::assertSame([], $plan['request']['context']);
        self::assertFalse($plan['request']['allow_actions']);
        self::assertSame([], $plan['next_actions']);
    }

    public function test_classroom_wording_does_not_suppress_real_estimate_request(): void
    {
        $plan = $this->makeOrchestratorWithRealRegistry()->plan(
            'Для учебного примера покажи реальную цену позиции сметы.', [],
            ['organization_id' => 15, 'permissions_flat' => ['estimates.view']]
        );

        self::assertSame('estimates', $plan['capability']['id']);
        self::assertContains('estimate', $plan['request_understanding']['requested_entities']);
    }

    public function test_classroom_wording_keeps_mixed_project_and_bim_requests_in_business_routing(): void
    {
        foreach ([
            'Реши учебный пример: 12 + 8 и покажи объём бетона в BIM-модели.',
            'Реши учебный пример: 12 + 8 и покажи наши проекты.',
        ] as $query) {
            self::assertFalse(\App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier::isConversationNumberRequest($query));
            $plan = $this->makeOrchestratorWithRealRegistry()->plan($query, [], ['organization_id' => 15, 'permissions_flat' => []]);
            self::assertNotNull($plan['capability']);
        }
    }

    #[DataProvider('russianIntentProvider')]
    public function test_plan_routes_russian_intent_keywords(string $query, string $expectedTaskType): void
    {
        $orchestrator = $this->makeOrchestrator();

        $plan = $orchestrator->plan($query, [], [
            'organization_id' => 15,
            'permissions_flat' => [],
        ]);

        $this->assertSame($expectedTaskType, $plan['task_type']);
    }

    public function test_generic_project_period_from_report_route_keeps_project_capability(): void
    {
        $orchestrator = $this->makeOrchestratorWithRealRegistry();

        $plan = $orchestrator->plan('с 1.04.2026 по 01.05.2026 по текущему проекту', [
            'context' => [
                'source_module' => 'ai-assistant',
                'source_route' => '/reports',
                'entity_refs' => [
                    [
                        'type' => 'project',
                        'id' => 56,
                        'label' => 'Строительство склада Литер А',
                    ],
                ],
            ],
        ], [
            'organization_id' => 15,
            'permissions_flat' => ['reports.view', 'schedule-management.view', 'projects.view'],
        ]);

        $this->assertSame('projects', $plan['capability']['id'] ?? null);
        $this->assertSame('/reports', $plan['request']['context']['source_route']);
        $this->assertSame(['route' => '/projects/56'], $plan['navigation_target']);
    }

    public function test_nested_schedule_route_wins_over_project_route(): void
    {
        $orchestrator = $this->makeOrchestratorWithRealRegistry();

        $plan = $orchestrator->plan('за текущий период', [
            'context' => [
                'source_route' => '/projects/56/schedules',
                'entity_refs' => [
                    [
                        'type' => 'project',
                        'id' => 56,
                    ],
                ],
            ],
        ], [
            'organization_id' => 15,
            'permissions_flat' => ['schedule-management.view', 'projects.view'],
        ]);

        $this->assertSame('schedules', $plan['capability']['id'] ?? null);
        $this->assertSame(['route' => '/projects/56/schedules'], $plan['navigation_target']);
    }

    public function test_finance_summary_routes_to_payments_without_implicit_file_report(): void
    {
        $orchestrator = $this->makeOrchestratorWithRealRegistry();

        $plan = $orchestrator->plan('Собери короткую сводку по финансам', [], [
            'organization_id' => 15,
            'permissions_flat' => ['reports.view'],
        ]);

        $this->assertSame('summary', $plan['task_type']);
        $this->assertSame('payments', $plan['capability']['id'] ?? null);
    }

    public function test_project_module_does_not_override_nested_schedule_route(): void
    {
        $plan = $this->makeOrchestratorWithRealRegistry()->plan('за текущий период', ['context' => [
            'source_module' => 'project-management', 'source_route' => '/projects/56/schedules',
            'entity_refs' => [['type' => 'project', 'id' => 56]],
        ]], ['organization_id' => 15, 'permissions_flat' => ['schedule.view', 'projects.view']]);
        $this->assertSame('schedules', $plan['capability']['id']);
        $this->assertSame(['route' => '/projects/56/schedules'], $plan['navigation_target']);
    }

    public function test_explicit_estimate_request_keeps_estimate_capability_on_project_page(): void
    {
        $plan = $this->makeOrchestratorWithRealRegistry()->plan('Покажи смету проекта', ['context' => [
            'source_module' => 'project-management', 'source_route' => '/projects/56',
            'entity_refs' => [['type' => 'project', 'id' => 56]],
        ]], ['organization_id' => 15, 'permissions_flat' => ['projects.view', 'budget-estimates.view']]);
        $this->assertSame('estimates', $plan['capability']['id']);
        $this->assertSame('estimates', $plan['capability']['domain']);
    }

    public function test_data_capability_is_not_high_confidence_without_tool_evidence(): void
    {
        $orchestrator = $this->makeOrchestratorWithRealRegistry();

        $plan = $orchestrator->plan('сводка по отчетам', [
            'context' => [
                'source_route' => '/reports',
            ],
        ], [
            'organization_id' => 15,
            'permissions_flat' => ['reports.view'],
        ]);

        $withoutToolEvidence = $orchestrator->buildPayload($plan, 'Нет подтвержденных данных.');
        $withToolEvidence = $orchestrator->buildPayload($plan, 'Отчет основан на данных графика.', [
            'tool_evidence' => [
                [
                    'label' => 'get_schedule_snapshot',
                    'value' => 'Инструмент выполнен',
                ],
            ],
        ]);

        $this->assertSame('medium', $withoutToolEvidence['confidence']);
        $this->assertSame('high', $withToolEvidence['confidence']);
    }

    public function test_rag_payload_does_not_mark_domain_missing_when_sources_exist(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $plan = $orchestrator->plan('Что известно из базы знаний?', [], [
            'organization_id' => 15,
            'permissions_flat' => [],
        ]);

        $payload = $orchestrator->buildPayload($plan, 'Ответ по базе знаний.', [
            'rag_context' => [
                'enabled' => true,
                'used' => true,
                'sources' => [
                    [
                        'title' => 'Project memo',
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $payload['missing_data']);
    }

    public function test_plan_contains_request_understanding_for_text_only_rag_request(): void
    {
        $orchestrator = $this->makeOrchestratorWithRealRegistry();

        $plan = $orchestrator->plan(
            'По проекту «Кирпичный дом "Лесной двор"» перечисли 5 фактов из базы знаний. Только текст. Не создавай PDF, файл или отчет.',
            [],
            [
                'organization_id' => 15,
                'permissions_flat' => ['projects.view', 'reports.view'],
            ]
        );

        $this->assertSame('search_knowledge', $plan['request_understanding']['primary_intent']);
        $this->assertSame('text', $plan['request_understanding']['output_format']);
        $this->assertSame('read_only', $plan['request_understanding']['action_policy']);
        $this->assertContains('no_pdf', $plan['request_understanding']['constraints']);
        $this->assertContains('no_report', $plan['request_understanding']['constraints']);
    }

    public function test_json_no_actions_payload_removes_navigation_and_next_actions(): void
    {
        $orchestrator = $this->makeOrchestratorWithRealRegistry();

        $plan = $orchestrator->plan('Ответь строго JSON без markdown. Без действий и без навигации.', [
            'context' => [
                'source_route' => '/projects',
            ],
        ], [
            'organization_id' => 15,
            'permissions_flat' => ['projects.view'],
        ]);

        $payload = $orchestrator->buildPayload($plan, '{"ok":true}');

        $this->assertSame('json', $plan['request_understanding']['output_format']);
        $this->assertSame([], $payload['next_actions']);
        $this->assertNull($payload['navigation_target']);
        $this->assertFalse($payload['requires_confirmation']);
    }

    public static function russianIntentProvider(): array
    {
        return [
            'navigate' => ['открой график проекта', 'navigate'],
            'act' => ['создай задачу в графике', 'act'],
            'find' => ['найди проект Дом 300', 'find'],
            'analyze' => ['проанализируй риски по срокам', 'analyze'],
            'finance skew analyze' => ['есть ли перекос по финансам', 'analyze'],
            'wizard' => ['помоги оформить заявку пошагово', 'wizard'],
        ];
    }

    private function makeOrchestrator(): AssistantTaskOrchestrator
    {
        $registry = $this->createMock(AssistantCapabilityRegistry::class);
        $registry->method('match')->willReturn(null);

        $accessContextResolver = $this->createMock(AssistantAccessContextResolver::class);
        $accessContextResolver->method('toPublicContext')->willReturn([
            'organization_id' => 15,
            'available_modules' => [],
            'permission_count' => 0,
            'is_read_only' => true,
            'allowed_action_types' => ['summary', 'find', 'analyze', 'navigate'],
        ]);

        return new AssistantTaskOrchestrator($registry, $accessContextResolver);
    }

    private function makeOrchestratorWithRealRegistry(): AssistantTaskOrchestrator
    {
        $accessContextResolver = $this->createMock(AssistantAccessContextResolver::class);
        $accessContextResolver->method('toPublicContext')->willReturn([
            'organization_id' => 15,
            'available_modules' => ['reports', 'schedules', 'projects'],
            'permission_count' => 3,
            'is_read_only' => true,
            'allowed_action_types' => ['summary', 'find', 'analyze', 'navigate'],
        ]);
        $accessContextResolver->method('hasAnyPermission')->willReturn(true);

        return new AssistantTaskOrchestrator(new AssistantCapabilityRegistry, $accessContextResolver);
    }
}
