<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportSlotResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class AssistantReportSlotResolverTest extends TestCase
{
    public function test_resolves_period_without_defaulting_empty_prompt(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->resolvePeriod(''));
        $this->assertNull($resolver->resolvePeriod('sformiruy otchet'));

        $period = $resolver->resolvePeriod($this->ru('\u0437\u0430 \u043f\u0440\u043e\u0448\u043b\u044b\u0439 \u043c\u0435\u0441\u044f\u0446'));

        $this->assertSame('2026-04-01', $period?->dateFrom);
        $this->assertSame('2026-04-30', $period?->dateTo);
    }

    public function test_resolves_entity_from_context(): void
    {
        $entity = $this->resolver()->entityFromContext([
            'entity_refs' => [
                ['type' => 'project', 'id' => '56', 'label' => 'Project A'],
            ],
        ], 'project');

        $this->assertSame(56, $entity['id']);
        $this->assertSame('Project A', $entity['label']);
    }

    public function test_builds_tool_arguments_for_report_slots(): void
    {
        $state = new AssistantTaskState(
            id: 'report.time_tracking',
            domain: 'reports',
            capability: 'workforce',
            toolName: 'generate_time_tracking_report',
            status: 'ready_to_execute',
            slots: [
                new AssistantTaskSlot('period', true, [
                    'date_from' => '2026-04-01',
                    'date_to' => '2026-04-30',
                    'source_text' => 'previous month',
                ]),
                new AssistantTaskSlot('project_id', false, 12),
                new AssistantTaskSlot('user_id', false, 77),
            ],
            sourceMessage: 'time tracking report'
        );

        $this->assertSame([
            'period' => 'previous month',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
            'project_id' => 12,
            'user_id' => 77,
        ], $this->resolver()->toolArguments($state));
    }

    private function resolver(): AssistantReportSlotResolver
    {
        return new AssistantReportSlotResolver(
            new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'))
        );
    }

    private function ru(string $escaped): string
    {
        $decoded = json_decode('"'.$escaped.'"');

        $this->assertIsString($decoded);

        return $decoded;
    }
}
