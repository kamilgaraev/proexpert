<?php

declare(strict_types=1);

namespace Tests\Feature\WorkforceManagement\Timekeeping;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Models\TimekeepingPeriod;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\MonthlyTimekeepingCalculator;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingRules;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingService;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingSnapshotHash;
use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingSourceReader;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MonthlyTimekeepingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_is_idempotent_and_revision_conflicts_do_not_add_snapshot(): void
    {
        $context = AdminApiTestContext::create();
        $this->access();
        $service = app(TimekeepingService::class);
        $first = $service->store($context->organization->id, $context->user, ['month' => '2026-09']);
        $again = $service->store($context->organization->id, $context->user, ['month' => '2026-09']);
        self::assertSame($first['id'], $again['id']);
        self::assertSame(1, $first['revision']);
        self::assertSame(1, DB::table('workforce_timekeeping_revisions')->count());
        $second = $service->refresh($context->organization->id, $context->user, $first['id'], 1);
        self::assertSame(2, $second['revision']);
        try {
            $service->refresh($context->organization->id, $context->user, $first['id'], 1);
            self::fail('stale revision accepted');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.timekeeping_revision_conflict'), $exception->getMessage());
        }
        self::assertSame(2, DB::table('workforce_timekeeping_revisions')->count());
    }

    public function test_private_projection_hides_diagnosis_corrections_intervals_and_evidence(): void
    {
        $context = AdminApiTestContext::create();
        $this->access(false);
        $snapshot = ['rows' => [['employee_id' => 1, 'days' => [['work_date' => '2026-09-01', 'absence_code' => 'medical_diagnosis', 'correction' => ['basis_reference' => 'private'], 'intervals' => [['start_at' => 'private']], 'unresolved_facts' => [['notes' => 'private']], 'sources' => [['type' => 'absence', 'id' => 99], ['type' => 'manual_cell', 'id' => 98], ['type' => 'schedule', 'id' => 1]]]]]], 'summary' => ['blocking_issue_count' => 0], 'issues' => []];
        $period = $this->period($context, 'draft', $snapshot);
        $payload = app(TimekeepingService::class)->show($context->organization->id, $context->user, $period->id);
        $day = $payload['rows'][0]['days'][0];
        self::assertSame('absence', $day['absence_code']);
        self::assertArrayNotHasKey('correction', $day);
        self::assertArrayNotHasKey('intervals', $day);
        self::assertArrayNotHasKey('unresolved_facts', $day);
        self::assertEquals([['type' => 'schedule', 'id' => 1]], $day['sources']);
        self::assertNull($payload['approval']);
    }

    public function test_approval_rejects_sources_changed_after_verification(): void
    {
        $context = AdminApiTestContext::create();
        $this->access();
        $service = app(TimekeepingService::class);
        $period = $service->store($context->organization->id, $context->user, ['month' => '2026-09']);
        $service->action($context->organization->id, $context->user, $period['id'], ['action' => 'submit', 'expected_revision' => 1]);
        $verified = $service->action($context->organization->id, $context->user, $period['id'], ['action' => 'verify', 'expected_revision' => 1]);
        DB::table('workforce_employees')->insert(['organization_id' => $context->organization->id, 'personnel_number' => 'NEW-SOURCE', 'first_name' => 'Иван', 'last_name' => 'Петров', 'hire_date' => '2026-01-01']);

        try {
            $service->action($context->organization->id, $context->user, $period['id'], ['action' => 'approve', 'expected_revision' => 1]);
            self::fail('changed source approved');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('workforce.errors.timekeeping_source_changed'), $exception->getMessage());
        }
        $persisted = TimekeepingPeriod::query()->findOrFail($period['id']);
        self::assertSame('verified', $persisted->status);
        self::assertNull($persisted->approval);
        self::assertSame($verified['snapshot_sha256'], $persisted->snapshot_sha256);
        self::assertSame(0, DB::table('workforce_timekeeping_transitions')->where('action', 'approve')->count());
    }

    public function test_foreign_employee_cannot_be_corrected_even_by_authorized_manager(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $this->access();
        $employeeId = DB::table('workforce_employees')->insertGetId(['organization_id' => $foreign->organization->id, 'personnel_number' => 'FOREIGN', 'first_name' => 'Иван', 'last_name' => 'Петров', 'hire_date' => '2026-01-01']);
        $service = app(TimekeepingService::class);
        $period = $service->store($context->organization->id, $context->user, ['month' => '2026-09']);
        $this->expectException(\DomainException::class);
        try {
            $service->cell($context->organization->id, $context->user, $period['id'], ['expected_revision' => 1, 'employee_id' => $employeeId, 'work_date' => '2026-09-01', 'reason_code' => 'manual_correction', 'basis_reference' => 'Приказ', 'intervals' => []]);
        } finally {
            self::assertSame(0, DB::table('workforce_timekeeping_cells')->count());
        }
    }

    public function test_source_reader_keeps_scoped_approved_and_draft_trips_with_grounds(): void
    {
        $context = AdminApiTestContext::create();
        $foreign = AdminApiTestContext::create();
        $employeeId = DB::table('workforce_employees')->insertGetId(['organization_id' => $context->organization->id, 'personnel_number' => 'TRAVEL', 'first_name' => 'Иван', 'last_name' => 'Петров', 'hire_date' => '2026-01-01']);
        $foreignEmployeeId = DB::table('workforce_employees')->insertGetId(['organization_id' => $foreign->organization->id, 'personnel_number' => 'OTHER', 'first_name' => 'Пётр', 'last_name' => 'Иванов', 'hire_date' => '2026-01-01']);
        $trip = ['organization_id' => $context->organization->id, 'employee_id' => $employeeId, 'start_date' => '2026-09-02', 'end_date' => '2026-09-03', 'status' => 'approved', 'destination' => 'Объект', 'reason_code' => 'business_trip', 'basis_reference' => 'Приказ К-1'];
        $approvedId = DB::table('workforce_business_trips')->insertGetId($trip);
        $draftId = DB::table('workforce_business_trips')->insertGetId(array_replace($trip, ['status' => 'draft']));
        DB::table('workforce_business_trips')->insert(array_replace($trip, ['status' => 'cancelled']));
        DB::table('workforce_business_trips')->insert(array_replace($trip, ['start_date' => '2026-08-01', 'end_date' => '2026-08-02']));
        DB::table('workforce_business_trips')->insert(array_replace($trip, ['organization_id' => $foreign->organization->id, 'employee_id' => $foreignEmployeeId]));

        $employees = app(TimekeepingSourceReader::class)->read($context->organization->id, '2026-09', null, 0);
        self::assertCount(1, $employees);
        self::assertEqualsCanonicalizing([$approvedId, $draftId], array_column($employees[0]['absences'], 'id'));
        foreach ($employees[0]['absences'] as $source) {
            self::assertSame('business_trip', $source['code']);
            self::assertSame('business_trip', $source['source_type']);
            self::assertSame('Приказ К-1', $source['basis_reference']);
        }
    }

    public function test_closed_period_rejects_changes_and_reopen_creates_new_period(): void
    {
        $context = AdminApiTestContext::create();
        $this->access();
        $period = $this->period($context, 'closed', ['rows' => [], 'summary' => ['blocking_issue_count' => 0], 'issues' => []]);
        $sha = $period->snapshot_sha256;
        $service = app(TimekeepingService::class);
        try {
            $service->refresh($context->organization->id, $context->user, $period->id, 1);
            self::fail('closed snapshot edited');
        } catch (\DomainException) {
        }
        $reopened = $service->action($context->organization->id, $context->user, $period->id, ['action' => 'reopen', 'expected_revision' => 1, 'basis_reference' => 'Исправление1']);
        self::assertNotSame($period->id, $reopened['id']);
        self::assertSame($period->id, $reopened['supersedes_id']);
        self::assertSame('draft', $reopened['status']);
        self::assertSame($sha, $period->fresh()->snapshot_sha256);
        self::assertSame('closed', $period->fresh()->status);
    }

    public function test_database_rejects_updates_to_closed_snapshot_even_without_service(): void
    {
        $context = AdminApiTestContext::create();
        $this->access();
        $period = $this->period($context, 'closed', ['rows' => [], 'summary' => ['blocking_issue_count' => 0], 'issues' => []]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::transaction(fn () => DB::table('workforce_timekeeping_periods')->where('id', $period->id)->update(['revision' => 2]));
    }

    public function test_paper_approval_closure_and_archive_bind_exact_document_revision_and_bytes(): void
    {
        $context = AdminApiTestContext::create();
        $this->access();
        $objects = [];
        $this->mock(FileService::class, function (MockInterface $mock) use (&$objects): void {
            $mock->shouldReceive('putPrivate')->andReturnUsing(static function (string $key, string $body, string $mime, string $sha256) use (&$objects): CurrentStoredFile {
                $objects[$key] = $body;

                return new CurrentStoredFile($key, 'etag', strlen($body), $sha256, $mime);
            });
            $mock->shouldReceive('readCurrent')->andReturnUsing(static function (string $key) use (&$objects) {
                $stream = fopen('php://temp', 'w+');
                fwrite($stream, $objects[$key]);
                rewind($stream);

                return $stream;
            });
        });
        $period = $this->period($context, 'verified', ['rows' => [], 'summary' => ['blocking_issue_count' => 0], 'issues' => []]);
        $period->month = (new \DateTimeImmutable('first day of last month'))->format('Y-m-01');
        $period->rule_snapshot = TimekeepingRules::snapshot(['form' => ['approved_basis_reference' => 'Приказ1', 'approved_at' => date('Y-m-d')], 'retention' => ['approved_basis_reference' => 'Политика1']]);
        $period->snapshot = app(MonthlyTimekeepingCalculator::class)->calculate(substr($period->month, 0, 7), [], $period->rule_snapshot);
        $period->snapshot_sha256 = TimekeepingSnapshotHash::calculate($period->snapshot);
        $period->save();
        $service = app(TimekeepingService::class);
        $document = $service->export($context->organization->id, $context->user, $period->id, 'csv');
        $file = UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\nPaper signature scan");
        $evidence = $service->uploadSignedFile($context->organization->id, $context->user, $period->id, $file, ['document_id' => $document['id'], 'expected_revision' => 1]);
        try {
            $service->action($context->organization->id, $context->user, $period->id, ['action' => 'approve', 'expected_revision' => 1, 'document_id' => $document['id'], 'document_revision' => 2, 'signed_file_id' => $evidence['file_id'], 'signer_name' => 'Иванов Иван', 'signed_on' => date('Y-m-d'), 'signed_at' => date(DATE_ATOM), 'paper_original_location' => 'Архив, дело1']);
            self::fail('wrong document revision accepted');
        } catch (\DomainException) {
            self::assertSame('verified', $period->fresh()->status);
        }
        $approved = $service->action($context->organization->id, $context->user, $period->id, ['action' => 'approve', 'expected_revision' => 1, 'document_id' => $document['id'], 'document_revision' => 1, 'signed_file_id' => $evidence['file_id'], 'signer_name' => 'Иванов Иван', 'signed_on' => date('Y-m-d'), 'signed_at' => date(DATE_ATOM), 'paper_original_location' => 'Архив, дело1']);
        self::assertSame('approved', $approved['status']);
        self::assertSame('paper', $approved['approval']['signing_method']);
        self::assertSame('operator_registered_paper_evidence', $approved['approval']['declaration']);
        $closed = $service->action($context->organization->id, $context->user, $period->id, ['action' => 'close', 'expected_revision' => 1]);
        self::assertSame('closed', $closed['status']);
        self::assertSame($approved['snapshot_sha256'], $closed['snapshot_sha256']);
        self::assertTrue($closed['archive_metadata']['disposal_requires_expert_review']);
        self::assertSame(5, $closed['archive_metadata']['retention_years']);
        self::assertSame(1, DB::table('workforce_timekeeping_documents')->where('format', 'json')->count());
        $archiveFile = \App\Models\File::query()->findOrFail($closed['archive_metadata']['file_id']);
        $manifest = json_decode($objects[$archiveFile->path], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($closed['snapshot_sha256'], $manifest['snapshot_sha256']);
        self::assertSame($manifest['snapshot_sha256'], TimekeepingSnapshotHash::calculate($manifest['snapshot']));
        self::assertEquals($approved['approval'], $manifest['approval']);
    }

    private function period(AdminApiTestContext $context, string $status, array $snapshot): TimekeepingPeriod
    {
        return TimekeepingPeriod::query()->create(['organization_id' => $context->organization->id, 'month' => '2026-09-01', 'status' => $status, 'revision' => 1, 'rule_snapshot' => TimekeepingRules::snapshot(), 'snapshot' => $snapshot, 'snapshot_sha256' => TimekeepingSnapshotHash::calculate($snapshot), 'created_by' => $context->user->id]);
    }

    private function access(bool $private = true): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($private): void {
            $mock->shouldReceive('canCurrent')->andReturnUsing(static fn ($actor, string $permission): bool => ! str_starts_with($permission, 'workforce.hr.private.') || $private);
        });
    }
}
