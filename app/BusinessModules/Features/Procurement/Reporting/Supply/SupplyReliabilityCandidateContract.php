<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyReliabilityPolicy;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyReliabilityFormula;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyReliabilityPeriod;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyReliabilitySnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class SupplyReliabilityCandidateContract
{
    public const CODE = 'supply_reliability';

    public const FORMULA_HASH = '608c0c2370ced80a02fe7f67337178b59a62a544038727eff3778678b42e6bac';

    public const SOURCE_HASH = '9b643e9ca162fb1fff2cfdfd02ecbca9aa8359d59bbcd5d5d074721035b76a07';

    public function filters(): array
    {
        return [['id' => 'period_start', 'required' => true], ['id' => 'period_end', 'required' => true]];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'original_promised_at', 'purchase_order_id', 'purchase_order_item_id',
            'supplier_id', 'ordered_quantity', 'net_received_quantity', 'delay_bucket', 'eligible',
            'on_time', 'in_full', 'stable_in_full', 'mature', 'otif', 'quantity_otif_numerator',
            'quantity_otif_denominator', 'value_otif_numerator_minor', 'value_otif_denominator_minor',
            'value_currency', 'value_basis', 'quality_warnings', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [['id' => 'original_promised_at', 'direction' => 'desc']];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'pdf'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(SupplyReliabilityFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classesHash([
                SupplyReliabilitySnapshotMaterializer::class,
                SupplyReliabilityPeriod::class,
                SupplyReliabilityPolicy::class,
            ]))) {
            throw new InvalidArgumentException('supply_reliability_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE || $definition->sourceModule !== 'procurement'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== 'supply-otif.v1'
            || $definition->sourceSchemaVersion !== 'supply-otif.v1'
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['procurement.purchase_orders.view']
            || $definition->permissionPolicy->exportPermissions !== ['procurement.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('supply_reliability_candidate_definition_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('supply_reliability_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('supply_reliability_candidate_source_unreadable');
            }
        }

        return hash_final($hash);
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR), $items);
    }
}
