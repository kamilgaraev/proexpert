<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceDurationEvidence;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\AttendanceExecutionFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabaseWorkforceReportAdapter;
use InvalidArgumentException;
use ReflectionClass;

final readonly class AttendanceExecutionCandidateContract
{
    public const CODE = 'attendance_execution';

    public const FORMULA_HASH = 'e6a3ad9e002e7c77ad662ded9ee460b8175783aeae31e41b7176a68a5fadab02';

    public const SOURCE_HASH = '0230035419e2adf002770d32375c6bc55e6598cf6cd375a1f68dd6a763f836f9';

    public const DURATION_EVIDENCE_HASH = 'f113f77c75a929fed1a1def4b361c24a7611994c7e5f67e6f4bfb80d2ca079f5';

    public function filters(): array
    {
        return [
            ['id' => 'day_from', 'required' => true],
            ['id' => 'day_to', 'required' => true],
            ['id' => 'statuses', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'work_date', 'employee_name', 'project_name', 'site_name', 'shift',
            'eligible_hours', 'confirmed_hours', 'actual_duration_known', 'approved_absence_hours',
            'unexplained_absence_hours', 'overtime_hours', 'late_hours', 'early_hours',
            'execution_percent', 'correction_rate', 'status_label', 'close_version',
        ]);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'direction' => $id === 'work_date'
                ? ReportSortDirection::DESC->value
                : ReportSortDirection::ASC->value,
        ], DatabaseWorkforceReportAdapter::SORTS[self::CODE]);
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(AttendanceExecutionFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(DatabaseWorkforceReportAdapter::class))
            || ! hash_equals(self::DURATION_EVIDENCE_HASH, self::classHash(AttendanceDurationEvidence::class))) {
            throw new InvalidArgumentException('attendance_execution_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'workforce-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== DatabaseWorkforceReportAdapter::ATTENDANCE_FORMULA
            || $definition->sourceSchemaVersion !== DatabaseWorkforceReportAdapter::ATTENDANCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['workforce.view']
            || $definition->permissionPolicy->exportPermissions !== ['workforce.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['workforce.audit.view']
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('attendance_execution_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('attendance_execution_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('attendance_execution_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(
                CanonicalJson::encode($item),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
            $items,
        );
    }
}
