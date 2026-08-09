<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowFormula;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class QualityDefectFlowCandidateContract
{
    public const CODE = 'quality_defect_flow';
    public const FORMULA_VERSION = 'quality_defect_flow_v1';
    public const SOURCE_SCHEMA_VERSION = 'quality_defect_flow_v1';
    public const FORMULA_HASH = 'acae0365d20840207443202b4e9992034797843ac61e64382823e398edbc3f7a';
    public const SOURCE_HASH = '31b7affddff5870f8628a2405e9892588d126322f49d44bc6a59ad38e08d3fa0';

    public function filters(): array
    {
        return [
            ['id' => 'period_from', 'required' => true],
            ['id' => 'period_to', 'required' => true],
            ['id' => 'severity', 'required' => false],
            ['id' => 'status', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'cohort_date', 'project_id', 'contractor_id', 'schedule_task_id',
            'quality_defect_id', 'event_version', 'severity', 'status', 'created',
            'reopened', 'closed', 'closing', 'cycle_days', 'due_date', 'evidence_refs', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'direction' => $id === 'cohort_date'
                ? ReportSortDirection::DESC->value
                : ReportSortDirection::ASC->value,
        ], ['cohort_date', 'due_date', 'severity', 'status', 'quality_defect_id']);
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(QualityDefectFlowFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(QualityDefectFlowSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('quality_defect_flow_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'quality-control'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['quality-control.defects.view']
            || $definition->permissionPolicy->exportPermissions !== ['quality-control.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== ['quality-control.defects.view']) {
            throw new InvalidArgumentException('quality_defect_flow_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('quality_defect_flow_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('quality_defect_flow_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR),
            $items,
        );
    }
}
