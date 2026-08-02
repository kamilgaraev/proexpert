<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

final class ReportSourceOwnerGeneration
{
    public static function capture(int $organizationId, string $sourceCode): array
    {
        $targetCursor = self::targetCursor($organizationId, $sourceCode);
        DB::table('report_source_generations')->insertOrIgnore([
            'organization_id' => $organizationId,
            'source_code' => $sourceCode,
            'revision' => 1,
            'watermark' => now(),
        ]);
        $generation = DB::table('report_source_generations')
            ->where('organization_id', $organizationId)
            ->where('source_code', $sourceCode)
            ->first(['revision', 'watermark']);
        if ($generation === null) {
            throw new LogicException('report_source_generation_missing');
        }
        $facts = [
            'revision' => (int) $generation->revision,
            'target_cursor' => $targetCursor,
            'watermark' => (string) $generation->watermark,
        ];

        return [
            'checksum' => hash('sha256', CanonicalJson::encode($facts)),
            'facts' => $facts,
            'target_cursor' => $targetCursor,
        ];
    }

    private static function targetCursor(int $organizationId, string $sourceCode): array
    {
        if ($sourceCode === 'safety_subject_lifecycle') {
            return [
                'incident_id' => self::maxId($organizationId, 'safety_incidents'),
                'violation_id' => self::maxId($organizationId, 'safety_violations'),
                'action_id' => self::maxId($organizationId, 'safety_corrective_actions'),
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

        return ['id' => (int) ($query->max('id') ?? 0)];
    }

    private static function maxId(int $organizationId, string $table): int
    {
        return (int) (DB::table($table)
            ->where('organization_id', $organizationId)
            ->max('id') ?? 0);
    }
}
