<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Reporting\FinanceSourceAccessPolicy;
use App\BusinessModules\Core\Payments\Reporting\SettlementAgingBucket;
use App\BusinessModules\Core\Payments\Services\Reports\SettlementAgingPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementExposureRow;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;
use App\Enums\CurrencyCode;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ContractSettlementExposureCandidateContract
{
    public const CODE = 'contract_settlement_exposure';
    public const FORMULA_VERSION = ContractSettlementCalculator::FORMULA_VERSION;
    public const SOURCE_SCHEMA_VERSION = 'contract_settlement_owner_history_v1';
    public const FORMULA_HASH = '8502d3b6f8c1ec8c08a1aa384ae38a33415e119a5522d577d242444734eb0067';
    public const SOURCE_HASH = '00fd6c6d98636d9d0d272578a0888afc50d19dc1f5cf0c7d3309a8f5e7362c54';

    public function filters(): array
    {
        return [
            ['id' => 'contract_ids', 'required' => false],
            ['id' => 'project_ids', 'required' => false],
            ['id' => 'allocation_ids', 'required' => false],
            ['id' => 'party_ids', 'required' => false],
            ['id' => 'directions', 'required' => false],
            ['id' => 'instruments', 'required' => false],
            ['id' => 'statuses', 'required' => false],
            ['id' => 'due_from', 'required' => false],
            ['id' => 'due_to', 'required' => false],
            ['id' => 'currencies', 'required' => false],
            ['id' => 'period_from', 'required' => false],
            ['id' => 'period_to', 'required' => false],
            ['id' => 'aging_buckets', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'contract_id', 'allocation_id', 'project_id', 'party_id', 'direction',
            'currency', 'effective', 'accepted', 'cash', 'settlement', 'unperformed_exposure',
            'unpaid_exposure', 'aging_bucket', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'contract_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'project_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'party_id', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'currency', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'effective', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'accepted', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'cash', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'settlement', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'unperformed_exposure', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'unpaid_exposure', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'aging_bucket', 'direction' => ReportSortDirection::ASC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classesHash([
            ContractSettlementCalculator::class,
            ContractSettlementExposureRow::class,
            SettlementAgingPolicy::class,
            SettlementAgingBucket::class,
            CurrencyCode::class,
        ])) || ! hash_equals(self::SOURCE_HASH, self::classesHash([
            ContractSettlementOwnerSource::class,
            ContractSettlementProjectionService::class,
            ContractSettlementAllocationConserver::class,
            ContractSettlementOwnerTimestamp::class,
            ContractSettlementInput::class,
            FinanceSourceAccessPolicy::class,
        ]))) {
            throw new InvalidArgumentException('contract_settlement_exposure_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'contract-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['contracts.management_report.view']
            || $definition->permissionPolicy->exportPermissions !== ['contracts.management_report.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('contract_settlement_exposure_candidate_definition_invalid');
        }
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('contract_settlement_exposure_candidate_source_unreadable');
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
