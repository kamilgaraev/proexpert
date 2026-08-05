<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardFormula;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardSnapshotMaterializer;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ContractorScorecardCandidateContract
{
    public const CODE = 'contractor_scorecard';
    public const FORMULA_VERSION = 'contractor-scorecard.v1';
    public const SOURCE_SCHEMA_VERSION = 'contractor-scorecard.v1';
    public const FORMULA_HASH = 'a965552184893020d8da3da25875a0f6fe1ee7af6c8d5c373dd062b16a6d9d18';
    public const SOURCE_HASH = 'f2f8e82344c4f11521a0bc16cd6fc992f8ac9b4e64a7ccd482416afc478d9e7b';

    public function filters(): array
    {
        return [
            ['id' => 'as_of', 'required' => true],
            ['id' => 'cohort', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'profile_id', 'category_id', 'project_id', 'cohort_key', 'component_code',
            'unit_code', 'component_mean', 'sample_size', 'eligible_count', 'coverage', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id, 'direction' => ReportSortDirection::ASC->value],
            ['profile_id', 'category_id', 'cohort_key', 'project_id', 'component_code', 'row_key'],
        );
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (
            ! hash_equals(self::FORMULA_HASH, self::classHash(ContractorScorecardFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(ContractorScorecardSnapshotMaterializer::class))
        ) {
            throw new InvalidArgumentException('contractor_scorecard_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if (
            $definition->code !== self::CODE
            || $definition->sourceModule !== 'contractor-portal'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['contractor_marketplace.profile.view']
            || $definition->permissionPolicy->exportPermissions !== ['contractor_marketplace.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []
        ) {
            throw new InvalidArgumentException('contractor_scorecard_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('contractor_scorecard_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('contractor_scorecard_candidate_source_unreadable');
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
