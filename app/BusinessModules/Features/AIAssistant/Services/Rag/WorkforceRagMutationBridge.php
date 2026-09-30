<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantWorkforceCatalogMetadata;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class WorkforceRagMutationBridge
{
    public function changed(string $table, int $organizationId, int $id, array $seen = []): void
    {
        if (app(AssistantIndexingState::class)->paused()) {
            return;
        }
        foreach (AssistantWorkforceCatalogMetadata::recordDefinitions() as $type => $record) {
            if ($record['table'] !== $table || $record['module'] !== 'workforce-management') {
                continue;
            }
            if (in_array($type.':'.$id, $seen, true)) {
                return;
            }
            $seen[] = $type.':'.$id;
            $row = DB::table($table)->where('organization_id', $organizationId)->where('id', $id)->first();
            $projectId = $record['project_column'] !== null && $row !== null
                ? ($row->{$record['project_column']} ?? null) : null;
            app(RagIndexingCoordinator::class)->queueEntity($organizationId, $projectId === null ? null : (int) $projectId, $record['source'], $type, $id);
            if ($row !== null && isset($row->employee_id)) {
                app(RagIndexingCoordinator::class)->queueEntity($organizationId, null, 'workforce', 'workforce_employee', (int) $row->employee_id);
            }
            if ($row !== null) {
                $records = AssistantWorkforceCatalogMetadata::recordDefinitions();
                foreach ($record['parents'] as $column => $parent) {
                    if (($row->{$column} ?? null) !== null && isset($records[$parent['type']])) {
                        $this->changed($records[$parent['type']]['table'], $organizationId, (int) $row->{$column}, $seen);
                    }
                }
            }
            return;
        }
    }

    public function changedRows(string $table, int $organizationId, Builder $rows): void
    {
        if (app(AssistantIndexingState::class)->paused()) {
            return;
        }
        foreach ((clone $rows)->where('organization_id', $organizationId)->select('id')->lazyById(50) as $row) {
            $this->changed($table, $organizationId, (int) $row->id);
        }
    }

    public function payrollPeriod(int $organizationId, int $periodId): void
    {
        $this->changed('workforce_payroll_periods', $organizationId, $periodId);
        foreach (AssistantWorkforceCatalogMetadata::recordDefinitions() as $record) {
            if ($record['module'] !== 'workforce-management' || !array_key_exists('payroll_period_id', $record['parents'])) {
                continue;
            }
            $this->changedRows($record['table'], $organizationId, DB::table($record['table'])->where('payroll_period_id', $periodId));
        }
    }

    public function calculationVersion(int $organizationId, int $versionId): void
    {
        $this->changed('workforce_payroll_calculation_versions', $organizationId, $versionId);
        foreach (['workforce_payroll_calculation_source_rows', 'workforce_payroll_calculation_issues', 'workforce_payroll_calculation_transitions'] as $table) {
            $this->changedRows($table, $organizationId, DB::table($table)->where('calculation_version_id', $versionId));
        }
        $periodId = DB::table('workforce_payroll_calculation_versions')->where('organization_id', $organizationId)->where('id', $versionId)->value('payroll_period_id');
        if ($periodId !== null) {
            $this->changed('workforce_payroll_periods', $organizationId, (int) $periodId);
        }
    }
}
