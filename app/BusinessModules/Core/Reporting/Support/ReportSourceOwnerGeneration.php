<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class ReportSourceOwnerGeneration
{
    public static function capture(int $organizationId, string $sourceCode): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (self::tables($sourceCode) as $table) {
                DB::statement('LOCK TABLE '.$table.' IN SHARE MODE');
            }
        }
        if ($sourceCode === 'safety_subject_lifecycle') {
            $facts = [];
            $cursor = [];
            foreach ([
                'incident_id' => 'safety_incidents',
                'violation_id' => 'safety_violations',
                'action_id' => 'safety_corrective_actions',
            ] as $key => $table) {
                $query = DB::table($table)->where('organization_id', $organizationId);
                $cursor[$key] = (int) ($query->max('id') ?? 0);
            }
            foreach (self::tables($sourceCode) as $table) {
                $facts[$table] = self::tableContentFact($organizationId, $table);
            }

            return [
                'checksum' => hash('sha256', CanonicalJson::encode($facts)),
                'facts' => $facts,
                'target_cursor' => $cursor,
            ];
        }
        $table = match ($sourceCode) {
            'quality_defect_status_history' => 'quality_defect_status_history',
            'approved_workforce_attendance' => 'workforce_attendance_corrections',
            'safety_site_workforce_assignments' => 'workforce_employee_assignments',
            default => throw new LogicException('report_source_sync_unknown'),
        };
        $query = DB::table($table)->where('organization_id', $organizationId);
        if ($sourceCode === 'safety_site_workforce_assignments') {
            $query->where('status', 'active')
                ->whereNotNull('project_id')
                ->whereNull('deleted_at');
        }
        $id = (int) ($query->max('id') ?? 0);
        $watermarkColumn = $sourceCode === 'quality_defect_status_history' ? 'changed_at' : 'updated_at';
        $facts = [
            'id' => $id,
            'owner' => self::tableContentFact($organizationId, $table),
            'watermark' => (clone $query)->max($watermarkColumn),
            'dependencies' => self::dependentFacts($organizationId, $sourceCode),
        ];

        return [
            'checksum' => hash('sha256', CanonicalJson::encode($facts)),
            'facts' => $facts,
            'target_cursor' => ['id' => $id],
        ];
    }

    public static function tables(string $sourceCode): array
    {
        $primary = match ($sourceCode) {
            'quality_defect_status_history' => ['quality_defect_status_history'],
            'approved_workforce_attendance' => ['workforce_attendance_corrections'],
            'safety_site_workforce_assignments' => ['workforce_employee_assignments'],
            'safety_subject_lifecycle' => ['safety_incidents', 'safety_violations', 'safety_corrective_actions'],
            default => throw new LogicException('report_source_sync_unknown'),
        };

        return array_values(array_unique([
            ...$primary,
            ...self::dependentTables($sourceCode),
        ]));
    }

    private static function dependentFacts(int $organizationId, string $sourceCode): array
    {
        $facts = [];
        foreach (self::dependentTables($sourceCode) as $table) {
            $facts[$table] = self::tableContentFact($organizationId, $table);
        }

        return $facts;
    }

    private static function dependentTables(string $sourceCode): array
    {
        return match ($sourceCode) {
            'quality_defect_status_history' => ['quality_defect_status_history'],
            'approved_workforce_attendance' => ['safety_site_workforce_assignments', 'safety_sites'],
            'safety_site_workforce_assignments' => [
                'safety_site_workforce_assignments',
                'safety_workforce_lifecycle_events',
                'safety_admission_policy_versions',
                'safety_training_records',
                'safety_medical_exams',
                'safety_ppe_issues',
                'safety_employee_requirements',
            ],
            'safety_subject_lifecycle' => [
                'safety_transition_events',
                'safety_incident_policy_versions',
                'safety_exposure_days',
            ],
            default => [],
        };
    }

    private static function tableContentFact(int $organizationId, string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns);
        $query = DB::table($table)->where('organization_id', $organizationId);
        $hash = hash_init('sha256');
        (clone $query)
            ->select($columns)
            ->orderBy('id')
            ->chunkById(500, static function (Collection $rows) use ($columns, $hash): void {
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($columns as $column) {
                        $values[$column] = $row->{$column};
                    }
                    hash_update($hash, CanonicalJson::encode($values)."\n");
                }
            });
        $watermarks = [];
        foreach (array_intersect(
            ['changed_at', 'created_at', 'occurred_at', 'source_watermark', 'updated_at', 'valid_from', 'valid_to'],
            $columns,
        ) as $column) {
            $watermarks[$column] = (clone $query)->max($column);
        }
        $versions = [];
        foreach (array_intersect(
            ['event_version', 'formula_version', 'policy_version', 'schema_version', 'version'],
            $columns,
        ) as $column) {
            $versions[$column] = (clone $query)->max($column);
        }

        return [
            'content_hash' => hash_final($hash),
            'count' => (clone $query)->count(),
            'max_id' => (clone $query)->max('id'),
            'versions' => $versions,
            'watermarks' => $watermarks,
        ];
    }
}
