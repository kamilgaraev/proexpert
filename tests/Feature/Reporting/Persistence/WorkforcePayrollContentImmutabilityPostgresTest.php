<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Temporal\TemporalOwnerFactMaterializer;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

#[Group('postgresql')]
final class WorkforcePayrollContentImmutabilityPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    #[DataProvider('sealedContentWriters')]
    public function test_concurrent_content_insert_is_rejected_after_version_becomes_validated(string $writer): void
    {
        [$organizationId, $versionId, $sourceRowId, $employeeId, $projectId] = $this->fixture();
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'payroll-content-seal-'.bin2hex(random_bytes(6)),
        );
        $observer = $harness->independentConnection('payroll_content_observer');
        $children = [];
        $transactionOpen = false;

        try {
            DB::beginTransaction();
            $transactionOpen = true;
            DB::table('workforce_payroll_calculation_versions')
                ->where('id', $versionId)
                ->lockForUpdate()
                ->first();
            DB::table('workforce_payroll_calculation_versions')
                ->where('id', $versionId)
                ->update([
                    'status' => 'validated',
                    'validated_at' => now(),
                    'updated_at' => now(),
                ]);

            $children[] = $harness->spawn(
                1,
                static function () use (
                    $writer,
                    $organizationId,
                    $versionId,
                    $sourceRowId,
                    $employeeId,
                    $projectId,
                ): array {
                    try {
                        if ($writer === 'source') {
                            DB::table('workforce_payroll_calculation_source_rows')->insert([
                                'organization_id' => $organizationId,
                                'calculation_version_id' => $versionId,
                                'source_row_id' => $sourceRowId,
                                'employee_id' => $employeeId,
                                'project_id' => $projectId,
                                'work_date' => '2026-07-10',
                                'source_type' => 'timesheet_hours',
                                'hours' => '8.0000',
                                'rate_version_id' => null,
                                'rate_type' => null,
                                'rate' => null,
                                'amount' => null,
                                'currency' => null,
                                'source_refs' => '[]',
                                'row_hash' => str_repeat('a', 64),
                            ]);
                        } else {
                            DB::table('workforce_payroll_calculation_issues')->insert([
                                'organization_id' => $organizationId,
                                'calculation_version_id' => $versionId,
                                'source_issue_id' => null,
                                'source_row_id' => $sourceRowId,
                                'severity' => 'blocking',
                                'issue_code' => 'late_insert',
                                'employee_id' => $employeeId,
                                'project_id' => $projectId,
                                'audit_ref' => '{}',
                                'row_hash' => str_repeat('b', 64),
                            ]);
                        }

                        return ['denied' => false, 'sql_state' => null];
                    } catch (Throwable $exception) {
                        return ['denied' => true, 'sql_state' => (string) $exception->getCode()];
                    }
                },
            );
            $harness->release(1);
            $backendPid = $harness->waitForWorkerBackendPid(1);
            $harness->waitForPostgresWait($observer, $backendPid);

            DB::commit();
            $transactionOpen = false;
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame(
                ['denied' => true, 'sql_state' => 'P0001'],
                $harness->result(1),
            );
        } finally {
            if ($transactionOpen) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    public static function sealedContentWriters(): iterable
    {
        yield 'source row' => ['source'];
        yield 'issue' => ['issue'];
    }

    public function test_temporal_materializer_shadows_live_owner_rows_with_exact_as_of_payload(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'До изменения',
        ]);
        $firstFactAt = (string) DB::table('workforce_report_owner_facts')
            ->where('organization_id', $organization->id)
            ->where('source_table', 'projects')
            ->where('source_id', $project->id)
            ->value('recorded_at');
        $project->update(['name' => 'После изменения']);

        $scope = new ReportScope(
            (int) $organization->id,
            [(int) $organization->id],
            [(int) $project->id],
            [],
            new DateTimeZone('UTC'),
        );
        $lease = (new TemporalOwnerFactMaterializer(DB::connection()))->materializeExactState(
            $scope,
            new DateTimeImmutable($firstFactAt),
            ['projects'],
            'PROJECT_HISTORY_UNAVAILABLE',
        );

        self::assertSame(
            'До изменения',
            DB::table('projects')->where('id', $project->id)->value('name'),
        );
        $lease->release();
        self::assertSame(
            'После изменения',
            DB::table('projects')->where('id', $project->id)->value('name'),
        );
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $now = now();
        $employeeId = DB::table('workforce_employees')->insertGetId([
            'organization_id' => $organization->id,
            'personnel_number' => 'EMP-001',
            'last_name' => 'Тестов',
            'first_name' => 'Тест',
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $periodId = DB::table('workforce_payroll_periods')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'draft',
            'created_by_user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sourceRowId = DB::table('workforce_payroll_source_rows')->insertGetId([
            'organization_id' => $organization->id,
            'payroll_period_id' => $periodId,
            'employee_id' => $employeeId,
            'project_id' => $project->id,
            'work_date' => '2026-07-10',
            'source_type' => 'timesheet_hours',
            'hours' => '8.00',
            'amount' => '0.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $versionId = DB::table('workforce_payroll_calculation_versions')->insertGetId([
            'organization_id' => $organization->id,
            'payroll_period_id' => $periodId,
            'version' => 1,
            'status' => 'built',
            'source_hash' => str_repeat('c', 64),
            'formula_version' => 'payroll-readiness.v1',
            'source_row_count' => 1,
            'blocking_count' => 0,
            'warning_count' => 0,
            'built_by_user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            (int) $organization->id,
            $versionId,
            $sourceRowId,
            $employeeId,
            (int) $project->id,
        ];
    }
}
