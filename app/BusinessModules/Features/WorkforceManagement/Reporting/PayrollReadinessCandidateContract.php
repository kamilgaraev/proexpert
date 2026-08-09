<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use InvalidArgumentException;
use ReflectionClass;

final readonly class PayrollReadinessCandidateContract
{
    public const CODE = 'payroll_readiness';

    public const FORMULA_HASH = '3ca061e58f58ee1e293c46909e2271f0d46178dccd6abff0eef43421973f988a';

    public const SOURCE_HASH = 'fd800e0239ddda6e235c7eb67acfc69eb3d213a85b33a3fda1fe8fc120fb91f6';

    public function filters(): array
    {
        return [
            ['id' => 'organization_id', 'required' => true],
            ['id' => 'project_id', 'required' => true],
            ['id' => 'payroll_period_ids', 'required' => true],
            ['id' => 'employee_ids', 'required' => false],
            ['id' => 'issue_codes', 'required' => false],
            ['id' => 'severities', 'required' => false],
            ['id' => 'statuses', 'required' => false],
            ['id' => 'source_types', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'period_start', 'period_end', 'calculation_version', 'row_type',
            'employee_name', 'project_name', 'source_type', 'hours', 'rate', 'rate_type',
            'amount', 'currency', 'issue_code', 'severity', 'status', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'direction' => $id === 'period_start' ? ReportSortDirection::DESC->value : ReportSortDirection::ASC->value,
        ], DatabasePayrollReadinessAdapter::SORTS);
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(PayrollReadinessFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(DatabasePayrollReadinessAdapter::class))) {
            throw new InvalidArgumentException('payroll_readiness_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'workforce-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== DatabasePayrollReadinessAdapter::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== DatabasePayrollReadinessAdapter::SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['workforce.view']
            || $definition->permissionPolicy->exportPermissions !== ['workforce.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== ['workforce.audit.view']) {
            throw new InvalidArgumentException('payroll_readiness_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('payroll_readiness_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('payroll_readiness_candidate_source_unreadable');
        }

        return $hash;
    }

    private static function canonicalItems(array $items): array
    {
        return array_map(static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR), $items);
    }
}
