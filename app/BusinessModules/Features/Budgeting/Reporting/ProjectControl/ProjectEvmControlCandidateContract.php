<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlAmounts;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlMetricRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceIdentity;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectControlCoreSnapshotFactory;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectControlFormula;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectControlSourceAssembler;
use App\Enums\CurrencyCode;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ProjectEvmControlCandidateContract
{
    public const CODE = 'project_evm_control';
    public const FORMULA_VERSION = 'project_control_core.v1';
    public const SOURCE_SCHEMA_VERSION = 'project_control_v1';
    public const FORMULA_HASH = 'e653f7ec0c7cca52e54616d7e5e93478c8fd37d13551029eeffd2fddf5f53c44';
    public const SOURCE_HASH = 'cdced2ac8ffbb47ec2b400141003f0a0258327f0349efd3b3ba09a6875401754';

    public function filters(): array
    {
        return [
            ['id' => 'status_date', 'required' => true],
            ['id' => 'wbs_ids', 'required' => false],
            ['id' => 'task_ids', 'required' => false],
            ['id' => 'contractor_ids', 'required' => false],
            ['id' => 'cost_center_ids', 'required' => false],
            ['id' => 'currencies', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'wbs_code', 'task_id', 'currency', 'bac_minor', 'pv_minor', 'ev_minor',
            'ac_minor', 'approved_etc_minor', 'sv_minor', 'cv_minor', 'spi', 'cpi', 'eac_minor',
            'vac_minor', 'tcpi', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'wbs_code', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'task_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'currency', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'bac_minor', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'pv_minor', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'ev_minor', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'sv_minor', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'spi', 'direction' => ReportSortDirection::ASC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classesHash([
            ProjectControlFormula::class,
            ProjectControlAmounts::class,
            ProjectControlMetricRow::class,
            CurrencyCode::class,
        ])) || ! hash_equals(self::SOURCE_HASH, self::classesHash([
            ProjectControlSourceAssembler::class,
            ProjectControlCoreSnapshotFactory::class,
            ProjectControlSourceIdentity::class,
            ProjectControlSourceRow::class,
        ]))) {
            throw new InvalidArgumentException('project_evm_control_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'budgeting'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['reports.project_control.view']
            || $definition->permissionPolicy->exportPermissions !== ['reports.project_control.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['budgeting.wip_forecast.view_sensitive_costs']
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('project_evm_control_candidate_definition_invalid');
        }
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('project_evm_control_candidate_source_unreadable');
            }
        }

        return hash_final($hash);
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR),
            $items,
        );
    }
}
