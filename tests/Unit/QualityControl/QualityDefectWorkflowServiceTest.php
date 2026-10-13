<?php

declare(strict_types=1);

namespace Tests\Unit\QualityControl;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectWorkflowService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QualityDefectWorkflowServiceTest extends TestCase
{
    public function test_schedule_link_warns_without_claiming_an_actual_work_constraint(): void
    {
        $defect = new QualityDefect([
            'schedule_task_id' => 2831,
            'status' => QualityDefectStatusEnum::IN_PROGRESS,
        ]);
        $workflow = app(QualityDefectWorkflowService::class)->forDefect($defect);

        $this->assertSame([], $workflow->blockers);
        $this->assertSame('schedule_task_has_open_defect', $workflow->problemFlags[0]['code']);
        $this->assertSame('warning', $workflow->problemFlags[0]['severity']);
        $this->assertSame(2831, $workflow->problemFlags[0]['target_id']);
        $this->assertSame([
            trans_message('quality_control.problem_flags.schedule_task_has_open_defect'),
        ], $workflow->warnings);
    }

    #[DataProvider('calendarDeadlines')]
    public function test_deadline_becomes_overdue_on_the_next_calendar_day(string $date, bool $expectedOverdue): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 21:00:00'));
        $defect = new QualityDefect([
            'due_date' => $date,
            'status' => QualityDefectStatusEnum::IN_PROGRESS,
        ]);
        $workflow = app(QualityDefectWorkflowService::class)->forDefect($defect);

        $this->assertSame($expectedOverdue, $workflow->meta['overdue']);
        $this->assertSame($expectedOverdue, collect($workflow->problemFlags)->contains('code', 'quality_defect_overdue'));
    }

    public static function calendarDeadlines(): array
    {
        return [
            'today is still available' => ['2026-10-13', false],
            'future deadline' => ['2026-10-14', false],
            'yesterday is overdue' => ['2026-10-12', true],
        ];
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_defects_do_not_warn_or_block(QualityDefectStatusEnum $status): void
    {
        $this->travelTo(Carbon::parse('2026-10-13 21:00:00'));
        $defect = new QualityDefect([
            'due_date' => '2026-10-12',
            'schedule_task_id' => 2831,
            'status' => $status,
        ]);
        $workflow = app(QualityDefectWorkflowService::class)->forDefect($defect);

        $this->assertFalse($workflow->meta['overdue']);
        $this->assertSame([], $workflow->problemFlags);
        $this->assertSame([], $workflow->blockers);
    }

    public static function closedStatuses(): array
    {
        return [[QualityDefectStatusEnum::RESOLVED], [QualityDefectStatusEnum::CANCELLED]];
    }
}
