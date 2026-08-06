<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;
use ReflectionClass;

final readonly class WipCompletionForecastCandidateContract
{
    public const CODE = 'wip_completion_forecast';
    public const FORMULA_VERSION = ProjectControlMetricContract::VERSION;
    public const SOURCE_SCHEMA_VERSION = 'budgeting_epm_data_mart_v1';
    public const FORMULA_HASH = '4e42f6e1dbf929763ff2c78f5db678f1bc1bf4657addff46b364fc2cba7a7ce2';
    public const SOURCE_HASH = 'e8d8232fbbed7d8514fc46695bdb1fe3456d8d4e8910bd34c76bf55ddd2a1561';

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
            'row_key', 'project_name', 'wbs_code', 'responsibility_center_name', 'period', 'currency',
            'bac', 'pv', 'ev', 'ac', 'wip', 'ctc', 'eac', 'forecast_variance', 'spi', 'cpi',
            'quality_status', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'project_name', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'wbs_code', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'currency', 'direction' => ReportSortDirection::ASC->value],
            ['id' => 'wip', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'ctc', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'eac', 'direction' => ReportSortDirection::DESC->value],
            ['id' => 'forecast_variance', 'direction' => ReportSortDirection::DESC->value],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(ProjectControlMetricContract::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(ProjectFinanceProjectionService::class))) {
            throw new InvalidArgumentException('wip_completion_forecast_candidate_contract_drift');
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
            || $definition->permissionPolicy->viewPermissions !== ['budgeting.wip_forecast.view']
            || $definition->permissionPolicy->exportPermissions !== ['budgeting.wip_forecast.export']
            || $definition->permissionPolicy->sensitivePermissions !== ['budgeting.wip_forecast.view_sensitive_costs']
            || $definition->permissionPolicy->auditPermissions !== ['budgeting.wip_forecast.view_audit']
            || $definition->outputClassification->defaultClassification !== ReportDataClassification::STANDARD
            || $definition->outputClassification->sensitiveColumnIds !== [
                'ac',
                'bac',
                'cpi',
                'ctc',
                'eac',
                'ev',
                'forecast_variance',
                'pv',
            ]
            || $definition->outputClassification->auditColumnIds !== []
            || $definition->outputClassification->totalsSensitive
            || $definition->outputClassification->totalsAudit
            || $definition->outputClassification->provenanceAudit) {
            throw new InvalidArgumentException('wip_completion_forecast_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('wip_completion_forecast_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('wip_completion_forecast_candidate_source_unreadable');
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
