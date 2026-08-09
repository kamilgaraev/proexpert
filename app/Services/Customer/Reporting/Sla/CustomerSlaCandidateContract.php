<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaFormula;
use App\Services\Customer\Reporting\Sla\Services\CustomerSlaSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class CustomerSlaCandidateContract
{
    public const CODE = 'customer_sla';
    public const FORMULA_VERSION = 'customer-sla.v1';
    public const SOURCE_SCHEMA_VERSION = 'customer-sla.v1';
    public const FORMULA_HASH = '63e1999fa8411abbdbe0ea38b5012a61e587a11ca7150bde8c3245f3ebed9319';
    public const SOURCE_HASH = '210c986392899cb2a6c20ac9839ebe306f67d0fb4e373b8d93d31a3fbc834ff5';

    public function filters(): array
    {
        return [
            ['id' => 'period_from', 'required' => true],
            ['id' => 'period_to', 'required' => true],
            ['id' => 'workflow_types', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'project_id', 'customer_organization_id', 'workflow_type',
            'workflow_id', 'priority', 'status', 'opened_at', 'first_response_seconds',
            'resolution_seconds', 'open_aging_seconds', 'first_response_breached',
            'resolution_breached', 'actor_side_complete', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'direction' => $id === 'opened_at' ? ReportSortDirection::DESC->value : ReportSortDirection::ASC->value,
        ], ['opened_at', 'project_id', 'customer_organization_id', 'workflow_type', 'workflow_id', 'row_key']);
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        $formulaHash = self::classHash(CustomerSlaFormula::class);
        $sourceHash = self::classHash(CustomerSlaSnapshotMaterializer::class);
        if (! hash_equals(self::FORMULA_HASH, $formulaHash)
            || ! hash_equals(self::SOURCE_HASH, $sourceHash)) {
            throw new InvalidArgumentException(sprintf(
                'customer_sla_candidate_contract_drift:formula=%s:source=%s',
                $formulaHash,
                $sourceHash,
            ));
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'reports'
            || $definition->coreAccessMode !== ReportCoreAccessMode::REPORTING_WORKSPACE
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['customer.sla_report.view']
            || $definition->permissionPolicy->exportPermissions !== ['customer.sla_report.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('customer_sla_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('customer_sla_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('customer_sla_candidate_source_unreadable');
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
