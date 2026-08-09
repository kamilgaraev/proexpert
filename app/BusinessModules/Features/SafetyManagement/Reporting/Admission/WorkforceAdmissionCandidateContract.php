<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\WorkforceAdmissionFormula;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\WorkforceAdmissionSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class WorkforceAdmissionCandidateContract
{
    public const CODE = 'workforce_admission';
    public const FORMULA_VERSION = 'workforce_admission_v1';
    public const SOURCE_SCHEMA_VERSION = 'workforce_admission_v1';
    public const FORMULA_HASH = '4df1641fb5a5c69fffbc38b3a7ad11abe2d81869d15f46ce47be44384e43f106';
    public const SOURCE_HASH = 'f640304f456761c5729174acfc502e02ea97bde2edb636ceafb8155590effa1b';

    public function filters(): array
    {
        return [
            ['id' => 'status', 'required' => false],
            ['id' => 'mandatory', 'required' => false],
            ['id' => 'blocked', 'required' => false],
            ['id' => 'verified', 'required' => false],
        ];
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'snapshot_date', 'project_id', 'safety_site_id', 'workforce_assignment_id',
            'employee_id', 'requirement_code', 'requirement_type', 'status', 'mandatory',
            'blocked', 'verified', 'valid_until', 'evidence_id', 'medical_details', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'direction' => $id === 'snapshot_date' ? ReportSortDirection::DESC->value : ReportSortDirection::ASC->value,
        ], ['snapshot_date', 'status', 'valid_until', 'employee_id', 'row_key']);
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(WorkforceAdmissionFormula::class))
            || ! hash_equals(self::SOURCE_HASH, self::classHash(WorkforceAdmissionSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('workforce_admission_candidate_contract_drift');
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
            || $definition->permissionPolicy->sensitivePermissions !== ['safety-management.medical.view']
            || $definition->permissionPolicy->auditPermissions !== ['safety-management.view']) {
            throw new InvalidArgumentException('workforce_admission_candidate_definition_invalid');
        }
    }

    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) {
            throw new InvalidArgumentException('workforce_admission_candidate_sort_invalid');
        }
    }

    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('workforce_admission_candidate_source_unreadable');
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
