<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Backfill\QualityDefectFlowBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Backfill\WorkforceAdmissionBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyExposureBackfill;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill\SafetyIncidentBackfill;
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

    public function handle(
        QualityDefectFlowBackfill $quality,
        SafetyIncidentBackfill $incidents,
        SafetyExposureBackfill $exposure,
        WorkforceAdmissionBackfill $admission,
    ): void {
        DB::table('report_source_sync_ledgers')->insertOrIgnore([
            'organization_id' => $this->organizationId,
            'source_code' => $this->sourceCode,
            'cursor' => '{}',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $hasMore = DB::transaction(function () use ($quality, $incidents, $exposure, $admission): bool {
            $ledger = DB::table('report_source_sync_ledgers')
                ->where('organization_id', $this->organizationId)
                ->where('source_code', $this->sourceCode)
                ->lockForUpdate()
                ->first();
            $cursor = json_decode((string) $ledger->cursor, true, 512, JSON_THROW_ON_ERROR);
            [$result, $nextCursor, $hasMore] = $this->process($cursor, $quality, $incidents, $exposure, $admission);
            DB::table('report_source_sync_ledgers')->where('id', $ledger->id)->update([
                'cursor' => json_encode($nextCursor, JSON_THROW_ON_ERROR),
                'status' => $hasMore ? 'running' : ((int) $result['gap_count'] === 0 && (int) $result['unknown_count'] === 0 ? 'ready' : 'partial'),
                'source_count' => (int) $ledger->source_count + (int) $result['source_count'],
                'projected_count' => (int) $ledger->projected_count + (int) $result['projected_count'],
                'gap_count' => (int) $ledger->gap_count + (int) $result['gap_count'],
                'unknown_count' => (int) $ledger->unknown_count + (int) $result['unknown_count'],
                'source_watermark' => $result['source_watermark'] ?? $ledger->source_watermark,
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
        QualityDefectFlowBackfill $quality,
        SafetyIncidentBackfill $incidents,
        SafetyExposureBackfill $exposure,
        WorkforceAdmissionBackfill $admission,
    ): array {
        return match ($this->sourceCode) {
            self::QUALITY_DEFECTS => $this->linear($quality->nextBatch($this->organizationId, (int) ($cursor['id'] ?? 0)), $quality, (int) ($cursor['id'] ?? 0)),
            self::SAFETY_EXPOSURE => $this->linear($exposure->nextBatch($this->organizationId, (int) ($cursor['id'] ?? 0)), $exposure, (int) ($cursor['id'] ?? 0)),
            self::WORKFORCE_ADMISSION => $this->linear($admission->nextBatch($this->organizationId, (int) ($cursor['id'] ?? 0)), $admission, (int) ($cursor['id'] ?? 0)),
            self::SAFETY_INCIDENTS => $this->incident($incidents, $cursor),
            default => throw new \LogicException('report_source_sync_unknown'),
        };
    }

    private function linear(Collection $batch, object $backfill, int $currentCursor): array
    {
        $result = $backfill instanceof SafetyExposureBackfill
            ? $backfill->apply($this->organizationId, $batch)
            : $backfill->apply($batch);
        $result['unknown_count'] ??= 0;

        return [$result, ['id' => (int) ($batch->max('id') ?? $currentCursor)], $batch->count() === 500];
    }

    private function incident(SafetyIncidentBackfill $backfill, array $cursor): array
    {
        $batch = $backfill->nextBatch($this->organizationId, $cursor);
        $next = [
            'incident_id' => (int) ($batch['incidents']->max('id') ?? ($cursor['incident_id'] ?? 0)),
            'violation_id' => (int) ($batch['violations']->max('id') ?? ($cursor['violation_id'] ?? 0)),
            'action_id' => (int) ($batch['actions']->max('id') ?? ($cursor['action_id'] ?? 0)),
        ];
        $hasMore = collect($batch)->contains(static fn (Collection $items): bool => $items->count() === 500);

        return [$backfill->apply($batch), $next, $hasMore];
    }
}
