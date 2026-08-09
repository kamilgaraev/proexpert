<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverReadinessFormula;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverReadinessSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class HandoverReadinessCandidateContract
{
    public const CODE = 'handover_readiness';
    public const FORMULA_VERSION = 'handover.v1';
    public const SOURCE_SCHEMA_VERSION = 'handover-readiness.v1';
    public const FORMULA_HASH = '480796625d579f1b2887bc92674efe7dff6e178da9272f34d3da830dca9bb8c5';
    public const SOURCE_HASH = 'ae87888c76f1f642d28e77969791ecf659851a8bd775a65e859196ae69701c50';

    public function filters(): array
    {
        return [['id' => 'as_of', 'required' => true]];
    }
    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'project_id', 'acceptance_scope_id', 'location_id', 'package_id', 'gate_code', 'due_on',
            'mandatory_completeness', 'document_completeness', 'open_hard_blocker_count', 'attempt_count',
            'successful_result_count', 'ready', 'drill',
        ]);
    }
    public function sorts(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id, 'direction' => ReportSortDirection::ASC->value],
            ['due_on', 'project_id', 'location_id', 'package_id', 'gate_code', 'row_key'],
        );
    }
    public function formats(): array { return ['csv', 'xlsx']; }
    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, self::classHash(HandoverReadinessFormula::class)) || ! hash_equals(self::SOURCE_HASH, self::classHash(HandoverReadinessSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('handover_readiness_candidate_contract_drift');
        }
    }
    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE || $definition->sourceModule !== 'reports' || $definition->coreAccessMode !== ReportCoreAccessMode::REPORTING_WORKSPACE || $definition->formulaVersion !== self::FORMULA_VERSION || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION || $definition->filters !== self::canonicalItems($this->filters()) || $definition->columns !== self::canonicalItems($this->columns()) || $definition->sorts !== self::canonicalItems($this->sorts()) || $definition->formats !== $this->formats() || $definition->permissionPolicy->viewPermissions !== ['reports.project_readiness.view'] || $definition->permissionPolicy->exportPermissions !== ['reports.project_readiness.export'] || $definition->permissionPolicy->sensitivePermissions !== [] || $definition->permissionPolicy->auditPermissions !== []) {
            throw new InvalidArgumentException('handover_readiness_candidate_definition_invalid');
        }
    }
    public function assertSort(ReportWindowSort $sort): void
    {
        if (! in_array($sort->field, array_column($this->sorts(), 'id'), true)) throw new InvalidArgumentException('handover_readiness_candidate_sort_invalid');
    }
    private static function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName(); $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) throw new InvalidArgumentException('handover_readiness_candidate_source_unreadable');
        return $hash;
    }
    private static function canonicalItems(array $items): array
    {
        return array_map(static fn (array $item): array => json_decode(CanonicalJson::encode($item), true, 512, JSON_THROW_ON_ERROR), $items);
    }
}
