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

    public function test_report_route_keeps_follow_up_on_reports_capability(): void
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

        $this->assertSame('reports', $plan['capability']['id'] ?? null);
        $this->assertSame('/reports', $plan['request']['context']['source_route']);
        $this->assertSame(['route' => '/reports'], $plan['navigation_target']);
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

    public function test_finance_summary_routes_to_reports_capability(): void
    {
        $orchestrator = $this->makeOrchestratorWithRealRegistry();

        $plan = $orchestrator->plan('Собери короткую сводку по финансам', [], [
            'organization_id' => 15,
            'permissions_flat' => ['reports.view'],
        ]);

        $this->assertSame('summary', $plan['task_type']);
        $this->assertSame('reports', $plan['capability']['id'] ?? null);
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
