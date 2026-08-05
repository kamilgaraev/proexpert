<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ScheduleManagement\Reporting\DTO\BaselineScheduleVarianceRow;
use InvalidArgumentException;
use ReflectionClass;

final readonly class BaselineScheduleVarianceCandidateContract
{
    public const CODE = 'baseline_schedule_variance';

    public const FORMULA_VERSION = 'schedule.baseline-variance.v1';

    public const SOURCE_SCHEMA_VERSION = 'schedule_baseline_v1';

    public const FORMULA_HASH = 'c8c3b6d05e673a04d91ea3778d2b7c1a760cce39ab1ff00fbd184bb67d3b4d1a';

    public const SOURCE_HASH = '41f6e0b39d2ae0fbb17ce1b5ebe3414544aad99fb59a06e79070bd059a8e4565';

    public function filters(): array
    {
        return [
            ['id' => 'as_of', 'required' => true],
            ['id' => 'statuses', 'required' => false],
            ['id' => 'critical', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'wbs_code', 'task_name', 'planned_start', 'planned_end',
            'start_variance_days', 'end_variance_days', 'duration_variance_days',
            'total_float_days', 'free_float_days', 'critical', 'overdue',
            'overdue_days', 'status', 'warning_codes', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'end_variance_days', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'total_float_days', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'task_name', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'wbs_code', 'direction' => ReportSortDirection::ASC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(BaselineScheduleVarianceRow::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(BaselineScheduleSnapshotService::class))) {
            throw new InvalidArgumentException('baseline_schedule_variance_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'schedule-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['schedule.view']
            || $definition->permissionPolicy->exportPermissions !== ['schedule.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('baseline_schedule_variance_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('baseline_schedule_variance_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('baseline_schedule_variance_candidate_source_unreadable');
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
