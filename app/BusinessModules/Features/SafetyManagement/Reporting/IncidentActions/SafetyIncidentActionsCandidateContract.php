<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyIncidentFormula;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyIncidentSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class SafetyIncidentActionsCandidateContract
{
    public const CODE = 'safety_incident_actions';
    public const FORMULA_VERSION = 'safety_incident_actions_v1';
    public const SOURCE_SCHEMA_VERSION = 'safety_incident_actions_v1';
    public const FORMULA_HASH = 'f0e98a08eb88ece897c5e552142134cf8b3247fcd8e19a873760b7fad399c790';
    public const SOURCE_HASH = 'd52fda6ba9b3fccfad9f57a9a427a6bb2dd9fec45ef4767c1ceffb832b59a349';

    public function filters(): array
    {
        return [
            ['id' => 'period_from', 'required' => true],
            ['id' => 'period_to', 'required' => true],
            ['id' => 'subject_type', 'required' => false],
            ['id' => 'severity', 'required' => false],
            ['id' => 'status', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'event_date', 'project_id', 'safety_site_id', 'contractor_id',
            'subject_type', 'subject_id', 'event_version', 'category', 'severity',
            'status', 'owner_user_id', 'due_date', 'created', 'reopened', 'closed',
            'closure_verified', 'closure_days', 'evidence_id', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'direction' => $id === 'event_date' ? ReportSortDirection::DESC->value : ReportSortDirection::ASC->value,
        ], ['event_date', 'due_date', 'severity', 'status', 'row_key']);
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(SafetyIncidentFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(SafetyIncidentSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('safety_incident_actions_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'safety-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || $definition->filters !== self::canonicalItems($this->filters())
            || $definition->columns !== self::canonicalItems($this->columns())
            || $definition->sorts !== self::canonicalItems($this->sorts())
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['safety-management.view']
            || $definition->permissionPolicy->exportPermissions !== ['safety-management.reports.export']
            || $definition->permissionPolicy->sensitivePermissions !== []
            || $definition->permissionPolicy->auditPermissions !== ['safety-management.view']) {
            throw new InvalidArgumentException('safety_incident_actions_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('safety_incident_actions_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('safety_incident_actions_candidate_source_unreadable');
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
