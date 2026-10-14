<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\AttendanceExecutionCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceDurationEvidence;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceExecutionFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\WorkforceCapacityFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityBuiltinPublishedReport;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AttendanceExecutionPublishedContractTest extends TestCase
{
    public function test_runtime_guards_pin_adapter_and_duration_policy_without_changing_formula_implementations(): void
    {
        (new WorkforceCapacityCandidateContract)->assertRuntimeMatches();
        (new AttendanceExecutionCandidateContract)->assertRuntimeMatches();
        $this->assertSame($this->hash(DatabaseWorkforceReportAdapter::class), WorkforceCapacityCandidateContract::SOURCE_HASH);
        $this->assertSame($this->hash(DatabaseWorkforceReportAdapter::class), AttendanceExecutionCandidateContract::SOURCE_HASH);
        $this->assertSame($this->hash(AttendanceDurationEvidence::class), AttendanceExecutionCandidateContract::DURATION_EVIDENCE_HASH);
        $this->assertSame($this->hash(WorkforceCapacityFormula::class), WorkforceCapacityCandidateContract::FORMULA_HASH);
        $this->assertSame($this->hash(AttendanceExecutionFormula::class), AttendanceExecutionCandidateContract::FORMULA_HASH);
    }

    public function test_published_attendance_v2_prints_nullable_confirmed_duration_instead_of_placeholder_zero(): void
    {
        $definition = (new AttendanceExecutionBuiltinPublishedReport(new AttendanceExecutionCandidateContract))->definition()->payload();
        $this->assertSame('2.0.0', $definition->contractVersion);
        $this->assertSame('attendance.v2', $definition->formulaVersion);
        $this->assertSame('workforce-attendance-source.v2', $definition->sourceSchemaVersion);
        $this->assertSame('2.0.0', $definition->rendererVersion);
        $columns = array_column($definition->columns, 'id');
        $this->assertContains('eligible_hours', $columns);
        $this->assertContains('confirmed_hours', $columns);
        $this->assertContains('actual_duration_known', $columns);
        $this->assertContains('status_label', $columns);
        $this->assertNotContains('status', $columns);
        $this->assertNotContains('present_hours', $columns);
    }

    public function test_capacity_keeps_its_formula_schema_contract_and_columns(): void
    {
        $definition = (new WorkforceCapacityBuiltinPublishedReport(new WorkforceCapacityCandidateContract))->definition()->payload();
        $this->assertSame('1.0.0', $definition->contractVersion);
        $this->assertSame('workforce-capacity.v1', $definition->formulaVersion);
        $this->assertSame('workforce-report-source.v1', $definition->sourceSchemaVersion);
        $this->assertNotContains('confirmed_hours', array_column($definition->columns, 'id'));
        $this->assertContains('capacity_hours', array_column($definition->columns, 'id'));
    }

    public function test_attendance_guard_rejects_the_previous_shared_source_schema(): void
    {
        $contract = new AttendanceExecutionCandidateContract;
        $document = (new AttendanceExecutionBuiltinPublishedReport($contract))->document();
        $document['versions']['source_schema'] = DatabaseWorkforceReportAdapter::SCHEMA_VERSION;
        $definition = (new ReportDefinitionFactory)->fromManifest($document);
        $this->expectException(\InvalidArgumentException::class);
        $contract->assertDefinition($definition);
    }

    private function hash(string $class): string
    {
        return hash_file('sha256', (new ReflectionClass($class))->getFileName());
    }
}
