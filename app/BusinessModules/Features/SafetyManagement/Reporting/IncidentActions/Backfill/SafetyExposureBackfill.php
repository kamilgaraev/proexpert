<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyExposureProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SafetyExposureBackfill
{
    public function __construct(private readonly SafetyExposureProjector $projector) {}

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
            ->orderBy('attendance.id')
            ->limit(min(max($limit, 1), 500))
            ->get([
                'attendance.id',
                'attendance.project_id',
                'attendance.employee_id',
                'attendance.work_date',
                'attendance.status',
                'attendance.hours',
                'attendance.updated_at',
                'mapping.safety_site_id',
            ]);
    }

    public function synchronize(int $organizationId, int $limit = 500): array
    {
        $afterId = 0;
        $totals = ['source_count' => 0, 'projected_count' => 0, 'gap_count' => 0];
        do {
            $batch = $this->nextBatch($organizationId, $afterId, $limit);
            if ($batch->isEmpty()) {
                break;
            }
            $result = $this->apply($organizationId, $batch);
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $result[$key];
            }
            $afterId = (int) $batch->max('id');
        } while ($batch->count() === min(max($limit, 1), 500));

        return $totals;
    }

    public function apply(int $organizationId, Collection $batch): array
    {
        $projected = [];
        $gaps = 0;
        $keys = $batch->groupBy(
            static fn (object $row): string => $row->project_id.':'.$row->safety_site_id.':'.$row->work_date,
        );
        foreach ($keys as $rows) {
            $seed = $rows->first();
            if ($seed === null) {
                $gaps++;
                continue;
            }
            $allRows = $this->rowsForDay(
                $organizationId,
                (int) $seed->project_id,
                (int) $seed->safety_site_id,
                (string) $seed->work_date,
            );
            $first = $seed;
            $scaledHours = $allRows->sum(
                static fn (object $row): int => (int) round(((float) $row->hours) * 10_000),
            );
            $watermark = $rows->max('updated_at');
            try {
                $day = $this->projector->project(
                    $organizationId,
                    (int) $first->project_id,
                    (int) $first->safety_site_id,
                    CarbonImmutable::parse((string) $first->work_date),
                    sprintf('%d.%04d', intdiv($scaledHours, 10_000), $scaledHours % 10_000),
                    $allRows->pluck('employee_id')->unique()->count(),
                    $this->sourceCode(),
                    (string) $watermark,
                    true,
                );
            } catch (\Throwable) {
                $gaps++;
                continue;
            }
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

    private function rowsForDay(int $organizationId, int $projectId, int $siteId, string $workDate): Collection
    {
        return $this->latestDailyCorrections(DB::table('workforce_attendance_corrections as attendance')
            ->join('safety_site_workforce_assignments as mapping', function ($join) use ($siteId, $workDate): void {
                $join->on('mapping.organization_id', '=', 'attendance.organization_id')
                    ->on('mapping.employee_id', '=', 'attendance.employee_id')
                    ->on('mapping.project_id', '=', 'attendance.project_id')
                    ->where('mapping.safety_site_id', $siteId)
                    ->whereDate('mapping.valid_from', '<=', $workDate)
                    ->where(static function ($query) use ($workDate): void {
                        $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', $workDate);
                    });
            })
            ->where('attendance.organization_id', $organizationId)
            ->where('attendance.project_id', $projectId)
            ->whereDate('attendance.work_date', $workDate)
            ->get([
                'attendance.id',
                'attendance.employee_id',
                'attendance.status',
                'attendance.hours',
                'attendance.updated_at',
            ]));
    }

    public function latestDailyCorrections(Collection $corrections): Collection
    {
        return $corrections
            ->unique('id')
            ->sortBy('id')
            ->groupBy('employee_id')
            ->map(static fn (Collection $corrections): object => $corrections->last())
            ->filter(static fn (object $correction): bool => $correction->status === 'at_work' && $correction->hours !== null)
            ->values();
    }
}
