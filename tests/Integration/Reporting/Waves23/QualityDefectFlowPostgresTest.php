<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectTransitionRecorder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class QualityDefectFlowPostgresTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function transition_and_projection_constraints_are_present(): void
    {
        $this->requirePostgres();
        $constraints = collect(DB::select(
            'select conname from pg_constraint where conname in (?, ?, ?, ?, ?, ?)',
            [
                'quality_defect_transition_version_unique',
                'quality_defect_flow_row_unique',
                'quality_defect_flow_snapshot_counts_check',
                'quality_defect_flow_snapshot_due_check',
                'quality_defect_flow_snapshot_mature_check',
                'quality_defect_flow_policy_no_overlap',
            ],
        ))->pluck('conname')->sort()->values()->all();

        self::assertSame([
            'quality_defect_flow_policy_no_overlap',
            'quality_defect_flow_row_unique',
            'quality_defect_flow_snapshot_counts_check',
            'quality_defect_flow_snapshot_due_check',
            'quality_defect_flow_snapshot_mature_check',
            'quality_defect_transition_version_unique',
        ], $constraints);

        $triggers = collect(DB::select(
            "select tgname from pg_trigger where not tgisinternal and tgname in ('quality_defect_transition_events_immutable', 'quality_defect_flow_policies_immutable')",
        ))->pluck('tgname')->sort()->values()->all();
        self::assertSame([
            'quality_defect_flow_policies_immutable',
            'quality_defect_transition_events_immutable',
        ], $triggers);
    }

    #[Test]
    #[DataProvider('immutableHistoryMutations')]
    public function every_historical_field_is_immutable_in_postgres(string $column, mixed $value): void
    {
        $this->requirePostgres();
        $historyId = $this->historyId();

        $this->expectException(QueryException::class);

        DB::table('quality_defect_status_history')
            ->where('id', $historyId)
            ->update([$column => $value]);
    }

    public static function immutableHistoryMutations(): array
    {
        return [
            'quality defect' => ['quality_defect_id', 999999],
            'organization' => ['organization_id', 999999],
            'from status' => ['from_status', 'assigned'],
            'to status' => ['to_status', 'resolved'],
            'comment' => ['comment', 'changed'],
            'actor' => ['changed_by', null],
            'timestamp' => ['changed_at', '2026-07-30 11:00:00+00'],
            'dimensions' => ['reporting_dimensions', '{"project_id":999999}'],
            'evidence' => ['reporting_evidence_refs', '[]'],
        ];
    }

    #[Test]
    public function historical_row_cannot_be_deleted_in_postgres(): void
    {
        $this->requirePostgres();
        $historyId = $this->historyId();

        $this->expectException(QueryException::class);

        DB::table('quality_defect_status_history')->where('id', $historyId)->delete();
    }

    #[Test]
    public function recorder_rejects_a_conflicting_replay_for_the_same_history_id(): void
    {
        $this->requirePostgres();
        $history = QualityDefectStatusHistory::query()->findOrFail($this->historyId());
        $defect = QualityDefect::query()->findOrFail($history->quality_defect_id);
        $recorder = app(QualityDefectTransitionRecorder::class);
        $recorder->record($defect, $history);
        $history->comment = 'conflicting replay';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('quality_defect_transition_conflict');

        $recorder->record($defect, $history);
    }

    #[Test]
    public function explicit_null_dimensions_are_preserved_when_current_owner_values_change(): void
    {
        $this->requirePostgres();
        $history = QualityDefectStatusHistory::query()->findOrFail($this->historyId());
        $defect = QualityDefect::query()->findOrFail($history->quality_defect_id);
        $defect->update(['due_date' => '2026-08-15']);

        $event = app(QualityDefectTransitionRecorder::class)->record($defect, $history);

        self::assertNull($event->due_date);
        self::assertNull($event->contractor_id);
        self::assertNull($event->schedule_task_id);
    }

    private function historyId(): int
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $actor = User::factory()->create();
        $defectId = DB::table('quality_defects')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by' => $actor->id,
            'defect_number' => 'Q-IMMUTABLE-'.$organization->id,
            'title' => 'Immutable history',
            'severity' => 'major',
            'status' => 'open',
            'inspection_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('quality_defect_status_history')->insertGetId([
            'quality_defect_id' => $defectId,
            'organization_id' => $organization->id,
            'from_status' => null,
            'to_status' => 'open',
            'comment' => 'created',
            'changed_by' => $actor->id,
            'changed_at' => '2026-07-30 10:00:00+00',
            'reporting_dimensions' => json_encode([
                'contractor_id' => null,
                'due_date' => null,
                'project_id' => $project->id,
                'schedule_task_id' => null,
                'severity' => 'major',
            ], JSON_THROW_ON_ERROR),
            'reporting_evidence_refs' => json_encode([['id' => 1, 'type' => 'quality_defect_photo']], JSON_THROW_ON_ERROR),
        ]);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL contract');
        }
    }
}
