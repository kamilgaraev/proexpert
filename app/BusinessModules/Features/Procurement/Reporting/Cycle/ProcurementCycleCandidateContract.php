<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleFormula;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleReportAdapter;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ProcurementCycleCandidateContract
{
    public const CODE = 'procurement_cycle';

    public const FORMULA_HASH = '7fa0c79e701d081930247ed67ebe233b5318b16127341898b586600a43463d71';

    public const SOURCE_HASH = '91e3d0394ba1464adb7c0e4bffdce7acdd391e0cd79728ae372d5e4da13229e0';

    public function filters(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'required' => in_array($id, ['period_start', 'period_end'], true),
        ], [
            'period_start', 'period_end', 'project_ids', 'cohort_basis', 'current_stage', 'outcome',
            'priority', 'requester_id', 'buyer_id', 'material_id', 'material_category_id',
            'supplier_party_id', 'currency', 'award_amount_min', 'award_amount_max',
        ]);
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'cohort_date', 'purchase_request_line_id', 'request_number', 'material_name',
            'requester_id', 'buyer_id', 'priority', 'current_stage', 'outcome', 'total_cycle_seconds',
            'open_age_seconds', 'awarded_supplier_party_id', 'awarded_amount', 'currency', 'quality_status',
            'gap_codes', ProcurementCycleReportAdapter::STAGE_DRILL_COLUMN, ProcurementCycleReportAdapter::AUDIT_DRILL_COLUMN,
        ]);
    }

    public function sorts(): array
    {
        return [['id' => ProcurementCycleReportAdapter::SORT_FIELD, 'direction' => 'asc']];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'pdf'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(ProcurementCycleFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(ProcurementCycleReportAdapter::class))) {
            throw new InvalidArgumentException('procurement_cycle_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'procurement'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== ProcurementCycleReportAdapter::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== ProcurementCycleReportAdapter::SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['procurement.dashboard.view']
            || $definition->permissionPolicy->exportPermissions !== ['procurement.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== ['procurement.audit.view']) {
            throw new InvalidArgumentException('procurement_cycle_candidate_definition_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('procurement_cycle_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR), $items);
    }
}
