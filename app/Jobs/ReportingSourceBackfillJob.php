<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Backfill\QualityDefectFlowBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Backfill\WorkforceAdmissionBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyExposureBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyIncidentBackfill;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ReportingSourceBackfillJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public const QUALITY_DEFECTS = 'quality_defect_status_history';
    public const SAFETY_INCIDENTS = 'safety_subject_lifecycle';
    public const SAFETY_EXPOSURE = 'approved_workforce_attendance';
    public const WORKFORCE_ADMISSION = 'safety_site_workforce_assignments';

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $sourceCode,
    ) {}

    public function uniqueId(): string
    {
        return $this->organizationId.':'.$this->sourceCode;
    }

    public static function request(int $organizationId, string $sourceCode): void
    {
        DB::transaction(function () use ($organizationId, $sourceCode): void {
            DB::table('report_source_sync_ledgers')->insertOrIgnore([
                'organization_id' => $organizationId,
                'source_code' => $sourceCode,
                'cursor' => '{}',
                'target_cursor' => '{}',
                'owner_checksum' => str_repeat('0', 64),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ledger = DB::table('report_source_sync_ledgers')
                ->where('organization_id', $organizationId)
                ->where('source_code', $sourceCode)
                ->lockForUpdate()
                ->first();
            if (DB::connection()->getDriverName() === 'pgsql') {
                foreach (self::ownerTables($sourceCode) as $table) {
                    DB::statement('LOCK TABLE '.$table.' IN SHARE MODE');
                }
            }
            [$targetCursor, $ownerFacts] = self::ownerCutoff($organizationId, $sourceCode);
            $checksum = hash('sha256', CanonicalJson::encode($ownerFacts));
            if ($ledger->completed_owner_checksum !== $checksum) {
                DB::table('report_source_sync_ledgers')->where('id', $ledger->id)->update([
                    'cursor' => '{}',
                    'target_cursor' => json_encode($targetCursor, JSON_THROW_ON_ERROR),
                    'owner_checksum' => $checksum,
                    'status' => 'pending',
                    'source_count' => 0,
                    'projected_count' => 0,
                    'gap_count' => 0,
                    'unknown_count' => 0,
                    'unknown_owner_keys' => '[]',
                    'completed_owner_checksum' => null,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);
            }
            self::dispatch($organizationId, $sourceCode)->afterCommit();
        });
    }

    public function handle(
        QualityDefectFlowBackfill $quality,
        SafetyIncidentBackfill $incidents,
        SafetyExposureBackfill $exposure,
        WorkforceAdmissionBackfill $admission,
    ): void {
        $hasMore = DB::transaction(function () use ($quality, $incidents, $exposure, $admission): bool {
            $ledger = DB::table('report_source_sync_ledgers')
                ->where('organization_id', $this->organizationId)
                ->where('source_code', $this->sourceCode)
                ->lockForUpdate()
                ->first();
            $cursor = json_decode((string) $ledger->cursor, true, 512, JSON_THROW_ON_ERROR);
            $target = json_decode((string) $ledger->target_cursor, true, 512, JSON_THROW_ON_ERROR);
            [$result, $nextCursor, $hasMore] = $this->process($cursor, $target, $quality, $incidents, $exposure, $admission);
            $totalGaps = (int) $ledger->gap_count + (int) $result['gap_count'];
            $totalUnknowns = (int) $ledger->unknown_count + (int) $result['unknown_count'];
            $unknownOwnerKeys = array_values(array_unique([
                ...json_decode((string) $ledger->unknown_owner_keys, true, 512, JSON_THROW_ON_ERROR),
                ...($result['unknown_owner_keys'] ?? []),
            ]));
            DB::table('report_source_sync_ledgers')->where('id', $ledger->id)->update([
                'cursor' => json_encode($nextCursor, JSON_THROW_ON_ERROR),
                'status' => $hasMore ? 'running' : ($totalGaps === 0 && $totalUnknowns === 0 ? 'ready' : 'partial'),
                'source_count' => (int) $ledger->source_count + (int) $result['source_count'],
                'projected_count' => (int) $ledger->projected_count + (int) $result['projected_count'],
                'gap_count' => (int) $ledger->gap_count + (int) $result['gap_count'],
                'unknown_count' => (int) $ledger->unknown_count + (int) $result['unknown_count'],
                'unknown_owner_keys' => json_encode($unknownOwnerKeys, JSON_THROW_ON_ERROR),
                'source_watermark' => $result['source_watermark'] ?? $ledger->source_watermark,
                'completed_owner_checksum' => $hasMore ? null : $ledger->owner_checksum,
                'completed_at' => $hasMore ? null : now(),
                'updated_at' => now(),
            ]);

            return $hasMore;
        }, 3);

        if ($hasMore) {
            self::dispatch($this->organizationId, $this->sourceCode);
        }
    }

    private function process(
        array $cursor,
        array $target,
        QualityDefectFlowBackfill $quality,
        SafetyIncidentBackfill $incidents,
        SafetyExposureBackfill $exposure,
        WorkforceAdmissionBackfill $admission,
    ): array {
        return match ($this->sourceCode) {
            self::QUALITY_DEFECTS => $this->linear($quality->nextBatch($this->organizationId, (int) ($cursor['id'] ?? 0)), $quality, (int) ($cursor['id'] ?? 0), (int) ($target['id'] ?? 0)),
            self::SAFETY_EXPOSURE => $this->linear($exposure->nextBatch($this->organizationId, (int) ($cursor['id'] ?? 0)), $exposure, (int) ($cursor['id'] ?? 0), (int) ($target['id'] ?? 0)),
            self::WORKFORCE_ADMISSION => $this->linear($admission->nextBatch($this->organizationId, (int) ($cursor['id'] ?? 0)), $admission, (int) ($cursor['id'] ?? 0), (int) ($target['id'] ?? 0)),
            self::SAFETY_INCIDENTS => $this->incident($incidents, $cursor, $target),
            default => throw new \LogicException('report_source_sync_unknown'),
        };
    }

    private function linear(Collection $batch, object $backfill, int $currentCursor, int $targetCursor): array
    {
        $batch = $batch->filter(static fn (object $row): bool => (int) $row->id <= $targetCursor)->values();
        $result = $backfill instanceof SafetyExposureBackfill
            ? $backfill->apply($this->organizationId, $batch)
            : $backfill->apply($batch);
        $result['unknown_count'] ??= 0;

        $nextCursor = (int) ($batch->max('id') ?? $currentCursor);

        return [$result, ['id' => $nextCursor], $nextCursor < $targetCursor];
    }

    private function incident(SafetyIncidentBackfill $backfill, array $cursor, array $target): array
    {
        $batch = $backfill->nextBatch($this->organizationId, $cursor);
        foreach (['incidents' => 'incident_id', 'violations' => 'violation_id', 'actions' => 'action_id'] as $key => $cursorKey) {
            $batch[$key] = $batch[$key]
                ->filter(static fn (object $row): bool => (int) $row->id <= (int) ($target[$cursorKey] ?? 0))
                ->values();
        }
        $next = [
            'incident_id' => (int) ($batch['incidents']->max('id') ?? ($cursor['incident_id'] ?? 0)),
            'violation_id' => (int) ($batch['violations']->max('id') ?? ($cursor['violation_id'] ?? 0)),
            'action_id' => (int) ($batch['actions']->max('id') ?? ($cursor['action_id'] ?? 0)),
        ];
        $hasMore = collect($next)->contains(
            static fn (int $value, string $key): bool => $value < (int) ($target[$key] ?? 0),
        );

        return [$backfill->apply($batch), $next, $hasMore];
    }

    private static function ownerCutoff(int $organizationId, string $sourceCode): array
    {
        if ($sourceCode === self::SAFETY_INCIDENTS) {
            $facts = [];
            $cursor = [];
            foreach ([
                'incident_id' => 'safety_incidents',
                'violation_id' => 'safety_violations',
                'action_id' => 'safety_corrective_actions',
            ] as $key => $table) {
                $query = DB::table($table)->where('organization_id', $organizationId);
                $cursor[$key] = (int) ($query->max('id') ?? 0);
                $facts[$key] = [$cursor[$key], $query->count(), $query->max('updated_at')];
            }

            return [$cursor, $facts];
        }
        $table = match ($sourceCode) {
            self::QUALITY_DEFECTS => 'quality_defect_status_history',
            self::SAFETY_EXPOSURE => 'workforce_attendance_corrections',
            self::WORKFORCE_ADMISSION => 'workforce_employee_assignments',
            default => throw new \LogicException('report_source_sync_unknown'),
        };
        $query = DB::table($table)->where('organization_id', $organizationId);
        if ($sourceCode === self::WORKFORCE_ADMISSION) {
            $query->where('status', 'active')
                ->whereNotNull('project_id')
                ->whereNull('deleted_at');
        }
        $id = (int) ($query->max('id') ?? 0);
        $watermarkColumn = $sourceCode === self::QUALITY_DEFECTS ? 'changed_at' : 'updated_at';

        return [['id' => $id], ['id' => $id, 'count' => $query->count(), 'watermark' => $query->max($watermarkColumn)]];
    }

    private static function ownerTables(string $sourceCode): array
    {
        return match ($sourceCode) {
            self::QUALITY_DEFECTS => ['quality_defect_status_history'],
            self::SAFETY_EXPOSURE => ['workforce_attendance_corrections'],
            self::WORKFORCE_ADMISSION => ['workforce_employee_assignments'],
            self::SAFETY_INCIDENTS => ['safety_incidents', 'safety_violations', 'safety_corrective_actions'],
            default => throw new \LogicException('report_source_sync_unknown'),
        };
    }
}
