<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessFormula;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessSnapshotMaterializer;
use InvalidArgumentException;
use ReflectionClass;

final readonly class LookaheadReadinessCandidateContract
{
    public const CODE = 'lookahead_readiness';

    public const FORMULA_VERSION = 'lookahead_readiness.v1';

    public const SOURCE_SCHEMA_VERSION = 'lookahead_events_v1';

    public const FORMULA_HASH = '2a038d2d3876dfcc103d2139a37549d4a8d5999cd32d0d0518e8c449c135175e';

    public const SOURCE_HASH = '46f4908a65866aeab8b12a8df343df9ca8297eb6c0e60fc05d82de5e9356d69d';

    public function definition(): ReportDefinition
    {
        $this->assertRuntimeMatches();

        return (new ReportDefinitionFactory)->fromManifest($this->document());
    }

    public function filters(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id, 'required' => $id === 'as_of'], [
            'as_of', 'horizon_days', 'project_ids', 'zone_ids', 'wbs_ids', 'owner_ids',
            'contractor_ids', 'task_statuses', 'constraint_types', 'severities', 'statuses',
        ]);
    }

    public function columns(): array
    {
        return array_map(static fn (string $id): array => ['id' => $id], [
            'row_key', 'project_id', 'wbs_code', 'task_id', 'planned_start_date', 'eligible',
            'ready', 'hard_blockers', 'soft_blockers', 'constraint_age_days', 'constraint_id',
            'constraint_type', 'constraint_severity', 'constraint_status', 'warning_code', 'drill',
        ]);
    }

    public function sorts(): array
    {
        return [
            ['id' => 'planned_start_date', 'direction' => 'asc'],
            ['id' => 'wbs_code', 'direction' => 'asc'],
            ['id' => 'constraint_age_days', 'direction' => 'desc'],
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx'];
    }

    public function assertRuntimeMatches(): void
    {
        if (! hash_equals(self::FORMULA_HASH, $this->classHash(LookaheadReadinessFormula::class))
            || ! hash_equals(self::SOURCE_HASH, $this->classHash(LookaheadReadinessSnapshotMaterializer::class))) {
            throw new InvalidArgumentException('lookahead_readiness_candidate_contract_drift');
        }
    }

    public function assertDefinition(ReportDefinition $definition): void
    {
        if ($definition->code !== self::CODE
            || $definition->sourceModule !== 'schedule-management'
            || $definition->coreAccessMode !== ReportCoreAccessMode::SOURCE_MODULE_REPORT
            || $definition->formulaVersion !== self::FORMULA_VERSION
            || $definition->sourceSchemaVersion !== self::SOURCE_SCHEMA_VERSION
            || array_column($definition->filters, 'id') !== array_column($this->filters(), 'id')
            || array_column($definition->columns, 'id') !== array_column($this->columns(), 'id')
            || array_column($definition->sorts, 'id') !== array_column($this->sorts(), 'id')
            || $definition->formats !== $this->formats()
            || $definition->permissionPolicy->viewPermissions !== ['schedule.view']
            || $definition->permissionPolicy->exportPermissions !== ['schedule.reports.export']) {
            throw new InvalidArgumentException('lookahead_readiness_candidate_definition_invalid');
        }
    }

    public function document(string $publication = 'blocked'): array
    {
        if (! in_array($publication, ['blocked', 'published'], true)) {
            throw new InvalidArgumentException('lookahead_readiness_publication_state_invalid');
        }

        return [
            'code' => self::CODE,
            'title_key' => 'reports.catalog.lookahead_readiness',
            'catalog_group' => 'projects',
            'category' => 'schedule',
            'grain' => 'constraint_task_window',
            'wave' => 2,
            'source_module' => 'schedule-management',
            'core_access_mode' => 'source_module_report',
            'filters' => $this->filters(),
            'columns' => $this->columns(),
            'sorts' => $this->sorts(),
            'formats' => $this->formats(),
            'versions' => ['contract' => '1.0.0', 'formula' => self::FORMULA_VERSION, 'source_schema' => self::SOURCE_SCHEMA_VERSION, 'renderer' => '1.0.0'],
            'semantic_fingerprints' => ['formula' => self::FORMULA_HASH, 'source' => self::SOURCE_HASH],
            'permissions' => ['view' => ['schedule.view'], 'export' => ['schedule.reports.export'], 'sensitive' => [], 'audit' => []],
            'readiness' => ['source' => 'ready', 'formula' => 'ready', 'delivery' => 'verified', 'publication' => $publication],
            'capabilities' => ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false],
        ];
    }

    private function classHash(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $hash = is_string($file) ? hash_file('sha256', $file) : false;
        if (! is_string($hash)) {
            throw new InvalidArgumentException('lookahead_readiness_candidate_source_unreadable');
        }

        return $hash;
    }
}
