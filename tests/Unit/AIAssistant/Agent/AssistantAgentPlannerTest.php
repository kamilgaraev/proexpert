<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssistantAgentPlannerTest extends TestCase
{
    public function test_pending_schedule_report_when_period_missing(): void
    {
        $decision = $this->planner()->decide(
            'Сделай отчет по графику работ',
            $this->projectContext(),
            null
        );

        $this->assertSame('ask_clarification', $decision->type);
        $this->assertSame('waiting_for_slots', $decision->state?->status);
        $this->assertSame('report.project_timelines', $decision->state?->id);
        $this->assertSame(['period'], $decision->state?->missingRequiredSlotNames());
        $this->assertSame(56, $decision->state?->slotValue('project_id'));
        $this->assertSame('За какой период сформировать отчет?', $decision->clarificationQuestion);
    }

    #[DataProvider('periodFollowUpProvider')]
    public function test_period_reply_continues_pending_schedule_report(string $reply, string $dateFrom, string $dateTo): void
    {
        $decision = $this->planner()->decide(
            $reply,
            [],
            $this->pendingScheduleState()
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('ready_to_execute', $decision->state?->status);
        $this->assertSame('generate_project_timelines_report', $decision->toolName);
        $this->assertSame($reply, $decision->toolArguments['period']);
        $this->assertSame($dateFrom, $decision->toolArguments['date_from']);
        $this->assertSame($dateTo, $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function periodFollowUpProvider(): array
    {
        return [
            'last month' => ['за последний месяц', '2026-04-04', '2026-05-04'],
            'november' => ['за ноябрь', '2025-11-01', '2025-11-30'],
            'two weeks' => ['за 2 недели', '2026-04-20', '2026-05-04'],
            'three weeks ago' => ['3 недели назад', '2026-04-07', '2026-04-14'],
        ];
    }

    public function test_direct_request_with_period_executes_immediately(): void
    {
        $decision = $this->planner()->decide(
            'Сформируй отчет по графику работ за 2 недели',
            $this->projectContext(),
            null
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('ready_to_execute', $decision->state?->status);
        $this->assertSame('generate_project_timelines_report', $decision->toolName);
        $this->assertSame('2026-04-20', $decision->toolArguments['date_from']);
        $this->assertSame('2026-05-04', $decision->toolArguments['date_to']);
        $this->assertSame(56, $decision->toolArguments['project_id']);
    }

    public function test_unknown_request_returns_answer_decision(): void
    {
        $decision = $this->planner()->decide('Расскажи, что умеешь', [], null);

        $this->assertSame('answer', $decision->type);
        $this->assertNull($decision->state);
        $this->assertNull($decision->toolName);
        $this->assertSame([], $decision->toolArguments);
        $this->assertNotSame('', $decision->clarificationQuestion);
    }

    public function test_project_id_is_optional_for_schedule_report(): void
    {
        $decision = $this->planner()->decide(
            'Сделай отчет по графику работ за ноябрь',
            ['source_module' => 'ai-assistant', 'entity_refs' => []],
            null
        );

        $this->assertSame('execute_tool', $decision->type);
        $this->assertSame('generate_project_timelines_report', $decision->toolName);
        $this->assertSame('2025-11-01', $decision->toolArguments['date_from']);
        $this->assertSame('2025-11-30', $decision->toolArguments['date_to']);
        $this->assertArrayNotHasKey('project_id', $decision->toolArguments);
    }

    private function planner(): AssistantAgentPlanner
    {
        return new AssistantAgentPlanner(
            new AssistantCapabilityCatalog,
            new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function projectContext(): array
    {
        return [
            'source_module' => 'ai-assistant',
            'entity_refs' => [
                ['type' => 'project', 'id' => 56, 'label' => 'Строительство склада Литер А'],
            ],
        ];
    }

    private function pendingScheduleState(): AssistantTaskState
    {
        return new AssistantTaskState(
            id: 'report.project_timelines',
            domain: 'reports',
            capability: 'schedules',
            toolName: 'generate_project_timelines_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('period', true),
                new AssistantTaskSlot('project_id', false, 56, 'Строительство склада Литер А'),
            ],
            sourceMessage: 'Сделай отчет по графику работ'
        );
    }
}
