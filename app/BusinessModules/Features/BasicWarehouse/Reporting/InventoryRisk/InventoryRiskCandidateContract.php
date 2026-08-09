<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskFormula;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskGrainUniverse;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskPeriod;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskSnapshotMaterializer;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\WarehouseDailyBalanceMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class InventoryRiskCandidateContract
{
    public const CODE = 'inventory_risk';
    public const FORMULA_HASH = '38ab50fe5b126ce090eb54f73fd230136c4bae9623c04eb60710680786a4fcd3';
    public const SOURCE_HASH = '7be6a337ff9fc3a1328ebebc3b6222b5b36aef6190ea8d8a604484a4d84d5260';

    public function filters(): array
    {
        return [
            ['id' => 'period_start', 'required' => true],
            ['id' => 'period_end', 'required' => true],
            ['id' => 'statuses', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'balance_date', 'warehouse_id', 'project_id', 'material_id', 'risk_status',
            'closing_on_hand', 'reserved_quantity', 'available_quantity', 'consumption_quantity',
            'turnover', 'cost_turnover', 'days_on_hand', 'stockout_at', 'consumption_value_minor',
            'on_hand_value_minor', 'currency', 'recommended_order_quantity', 'quality_warnings', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [['id' => 'balance_date', 'direction' => 'desc']];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'pdf'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(InventoryRiskFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classesHash([
                InventoryRiskSnapshotMaterializer::class,
                WarehouseDailyBalanceMaterializer::class,
                InventoryRiskGrainUniverse::class,
                InventoryRiskPeriod::class,
            ]))) {
            throw new InvalidArgumentException('inventory_risk_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE || $definition->sourceModule !== 'basic-warehouse'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== 'inventory-planning.v1'
            || $definition->sourceSchemaVersion !== 'inventory-planning.v1'
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['warehouse.advanced.view']
            || $definition->permissionPolicy->exportPermissions !== ['warehouse.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['warehouse.view_custody']
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('inventory_risk_candidate_definition_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('inventory_risk_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('inventory_risk_candidate_source_unreadable');
            }
        }

        return hash_final($hash);
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
