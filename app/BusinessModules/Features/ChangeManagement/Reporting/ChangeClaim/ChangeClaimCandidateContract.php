<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeClaimContingencyFormula;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeClaimSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class ChangeClaimCandidateContract
{
    public const CODE = 'change_claim_contingency';

    public const FORMULA_VERSION = 'change-claim-contingency.v1';

    public const SOURCE_SCHEMA_VERSION = 'change-claim-history.v1';

    public const FORMULA_HASH = '54cdf7e2799a088e466b26b95c507a9b5626e04272596aa775b83ab7979736e4';

    public const SOURCE_HASH = 'ac833c12470847d747d75e28f5ca6f6f8d1916a1290049b2201ab1ce4f55ba5e';

    public function definition(): ReportDefinition
    {
        $this->assertRuntimeMatches();

        return (new ReportDefinitionFactory)->fromManifest($this->document());
    }

    public function filters(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'required' => in_array($id, ['period_from', 'period_to'], true),
        ], [
            'period_from', 'period_to', 'project_ids', 'contract_ids', 'allocation_ids',
            'change_request_ids', 'claim_ids', 'statuses', 'currencies', 'initiator_types',
            'initiator_user_ids', 'owner_user_ids', 'reasons', 'source_types',
        ]);
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'occurred_on', 'project_id', 'contract_id', 'allocation_id',
            'change_request_id', 'change_version', 'status', 'currency',
            'proposed_exposure', 'approved_exposure', 'linked_claim', 'opening_contingency',
            'allocated_contingency', 'consumed_contingency', 'released_contingency',
            'closing_contingency', 'quality_status', 'drill',
        ]);
    }

    public function document(string $publication = 'blocked'): array
    {
        return [
            'code' => self::CODE,
            'title_key' => 'reports.catalog.change_claim_contingency',
            'catalog_group' => 'projects',
            'category' => 'control',
            'grain' => 'change_version_allocation_currency',
            'wave' => 3,
            'source_module' => 'change-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->filters(),
            'columns' => $this->columns(),
            'sorts' => [['id' => 'occurred_on', 'direction' => 'desc'], ['id' => 'project_id', 'direction' => 'asc'], ['id' => 'contract_id', 'direction' => 'asc'], ['id' => 'change_request_id', 'direction' => 'asc'], ['id' => 'currency', 'direction' => 'asc'], ['id' => 'closing_contingency', 'direction' => 'desc']],
            'formats' => ['csv', 'xlsx'],
            'versions' => ['contract' => '1.0.0', 'formula' => self::FORMULA_VERSION, 'source_schema' => self::SOURCE_SCHEMA_VERSION, 'renderer' => '1.0.0'],
            'semantic_fingerprints' => ['formula' => self::FORMULA_HASH, 'source' => self::SOURCE_HASH],
            'permissions' => ['view' => ['change-management.view'], 'export' => ['change-management.reports.export'], 'sensitive' => [], 'audit' => []],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => $publication],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'change-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || array_column($definition->filters, 'id') !== array_column($this->filters(), 'id')
            || array_column($definition->columns, 'id') !== array_column($this->columns(), 'id')
            || $definition->permissionPolicy->viewPermissions !== ['change-management.view']
            || $definition->permissionPolicy->exportPermissions !== ['change-management.reports.export']) {
            throw new InvalidArgumentException('change_claim_candidate_definition_invalid');
        }
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, $this->classHash(ChangeClaimContingencyFormula::class))
            || ! hash_equals(self::SOURCE_HASH, $this->classHash(ChangeClaimSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('change_claim_candidate_contract_drift');
        }
    }

    private function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;

        return is_string($hash) ? $hash : throw new InvalidArgumentException('change_claim_candidate_source_unreadable');
    }
}
