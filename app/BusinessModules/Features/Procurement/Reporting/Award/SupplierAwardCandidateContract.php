<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierAwardFormula;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\SupplierAwardSnapshotMaterializer;
use App\BusinessModules\Features\Procurement\Reporting\Award\Queries\SupplierAwardFilteredUniverse;
use InvalidArgumentException;
use ReflectionClass;

final readonly class SupplierAwardCandidateContract
{
    public const CODE = 'supplier_award_competitiveness';
    public const FORMULA_HASH = '793d196081a3c0773f558a74aee4f845529f20e9a98b574d7a7f1a3adee210a8';
    public const SOURCE_HASH = '156276ffbc15fb2e35986d8494ec1cc7c53b74aea8aaad9c9387c8a493fcd571';

    public function filters(): array
    {
        return [
            ['id' => 'period_start', 'required' => true],
            ['id' => 'period_end', 'required' => true],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'selected_at', 'decision_id', 'decision_version', 'proposal_version_id',
            'supplier_party_id', 'material_ids', 'currency', 'selected_amount_minor',
            'cheapest_amount_minor', 'premium_minor', 'premium_ratio', 'participation_ratio',
            'quality_warnings', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [['id' => 'selected_at', 'direction' => 'desc']];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'pdf'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(SupplierAwardFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classesHash([
                SupplierAwardSnapshotMaterializer::class,
                SupplierAwardFilteredUniverse::class,
            ]))) {
            throw new InvalidArgumentException('supplier_award_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'procurement'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== 'supplier-award.v1'
            || $definition->sourceSchemaVersion !== 'supplier-award.v1'
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['procurement.supplier_proposals.view']
            || $definition->permissionPolicy->exportPermissions !== ['procurement.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['procurement.proposal_decisions.view']
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('supplier_award_candidate_definition_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('supplier_award_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function classesHash(array $classes): string
    {
        $hash = hash_init('sha256');
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (! is_string($file) || ! hash_update_file($hash, $file)) {
                throw new InvalidArgumentException('supplier_award_candidate_source_unreadable');
            }
        }

        return hash_final($hash);
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR), $items);
    }
}
