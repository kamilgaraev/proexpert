<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyExposureProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SafetyExposureBackfill
{
    public function __construct(private SafetyExposureProjector $projector) {}

    public function sourceCode(): string
    {
        return 'approved_workforce_attendance';
    }

    public function sourceSchemaVersion(): string
    {
        return 'safety_exposure_v1';
    }

    public function nextBatch(int $organizationId, int $afterId, int $limit = 500): Collection
    {
        return DB::table('workforce_attendance_corrections as attendance')
            ->join('safety_site_workforce_assignments as mapping', function ($join): void {
                $join->on('mapping.organization_id', '=', 'attendance.organization_id')
                    ->on('mapping.employee_id', '=', 'attendance.employee_id')
                    ->on('mapping.project_id', '=', 'attendance.project_id')
                    ->whereColumn('mapping.valid_from', '<=', 'attendance.work_date')
                    ->where(static function ($query): void {
                        $query->whereNull('mapping.valid_to')
                            ->orWhereColumn('mapping.valid_to', '>=', 'attendance.work_date');
                    });
            })
            ->where('attendance.organization_id', $organizationId)
            ->where('attendance.id', '>', $afterId)
            ->where('attendance.status', 'at_work')
            ->whereNotNull('attendance.hours')
            ->orderBy('attendance.id')
            ->limit(min(max($limit, 1), 500))
            ->get([
                'attendance.id',
                'attendance.project_id',
                'attendance.employee_id',
                'attendance.work_date',
                'attendance.hours',
                'attendance.updated_at',
                'mapping.safety_site_id',
            ]);
    }

    public function apply(int $organizationId, Collection $batch): array
    {
        $projected = [];
        $gaps = 0;
        foreach ($batch->groupBy(
            static fn (object $row): string => $row->project_id.':'.$row->safety_site_id.':'.$row->work_date,
        ) as $rows) {
            $first = $rows->first();
            if ($first === null) {
                $gaps++;
                continue;
            }
            $scaledHours = $rows->sum(
                static fn (object $row): int => (int) round(((float) $row->hours) * 10_000),
            );
            $watermark = $rows->max('updated_at');
            $day = $this->projector->project(
                $organizationId,
                (int) $first->project_id,
                (int) $first->safety_site_id,
                CarbonImmutable::parse((string) $first->work_date),
                sprintf('%d.%04d', intdiv($scaledHours, 10_000), $scaledHours % 10_000),
                $rows->pluck('employee_id')->unique()->count(),
                $this->sourceCode(),
                (string) $watermark,
                true,
            );
            $projected[] = (string) $day->source_hash;
        }

        return [
            'source_count' => $batch->count(),
            'projected_count' => count($projected),
            'gap_count' => $gaps,
            'unknown_count' => 0,
            'input_hash' => hash('sha256', CanonicalJson::encode($batch->all())),
            'output_hash' => hash('sha256', implode('', $projected)),
            'source_watermark' => $batch->max('updated_at'),
        ];
    }
}
