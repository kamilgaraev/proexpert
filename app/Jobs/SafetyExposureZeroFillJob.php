<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyExposureDay;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services\SafetyExposureProjector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class SafetyExposureZeroFillJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly int $cursor = 0,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->organizationId, $this->periodFrom, $this->periodTo, $this->cursor]);
    }

    public function handle(SafetyExposureProjector $projector): void
    {
        $from = CarbonImmutable::parse($this->periodFrom)->startOfDay();
        $to = CarbonImmutable::parse($this->periodTo)->startOfDay();
        $sites = SafetySite::query()
            ->where('organization_id', $this->organizationId)
            ->whereDate('active_from', '<=', $to->toDateString())
            ->where(static function ($query) use ($from): void {
                $query->whereNull('active_until')->orWhereDate('active_until', '>=', $from->toDateString());
            })
            ->orderBy('id')
            ->get();
        $position = 0;
        $processed = 0;
        foreach ($sites as $site) {
            for ($date = $from; $date <= $to; $date = $date->addDay()) {
                if ($date < $site->active_from || ($site->active_until !== null && $date > $site->active_until)) {
                    continue;
                }
                if ($position++ < $this->cursor) {
                    continue;
                }
                if (! SafetyExposureDay::query()
                    ->where('organization_id', $this->organizationId)
                    ->where('safety_site_id', $site->id)
                    ->whereDate('exposure_date', $date->toDateString())
                    ->exists()
                    && $this->hasAuthoritativeZeroAttendance(
                        (int) $site->project_id,
                        (int) $site->id,
                        $date,
                    )) {
                    $projector->project(
                        $this->organizationId,
                        (int) $site->project_id,
                        (int) $site->id,
                        $date,
                        '0.0000',
                        0,
                        'approved_workforce_attendance',
                        $date->endOfDay()->toAtomString(),
                        true,
                    );
                }
                if (++$processed === 500) {
                    self::dispatch($this->organizationId, $this->periodFrom, $this->periodTo, $position);

                    return;
                }
            }
        }
    }

    private function hasAuthoritativeZeroAttendance(
        int $projectId,
        int $siteId,
        CarbonImmutable $date,
    ): bool {
        $employeeIds = DB::table('safety_site_workforce_assignments as mapping')
            ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
            ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
            ->where('mapping.organization_id', $this->organizationId)
            ->where('mapping.project_id', $projectId)
            ->where('mapping.safety_site_id', $siteId)
            ->where('assignment.organization_id', $this->organizationId)
            ->where('assignment.project_id', $projectId)
            ->where('assignment.status', 'active')
            ->whereNull('assignment.deleted_at')
            ->whereColumn('assignment.employee_id', 'mapping.employee_id')
            ->where('site.organization_id', $this->organizationId)
            ->where('site.project_id', $projectId)
            ->where('site.is_active', true)
            ->whereDate('mapping.valid_from', '<=', $date->toDateString())
            ->where(static fn ($query) => $query
                ->whereNull('mapping.valid_to')
                ->orWhereDate('mapping.valid_to', '>=', $date->toDateString()))
            ->distinct()
            ->pluck('mapping.employee_id');
        if ($employeeIds->isEmpty()) {
            return false;
        }
        $latest = DB::table('workforce_attendance_corrections')
            ->where('organization_id', $this->organizationId)
            ->where('project_id', $projectId)
            ->whereDate('work_date', $date->toDateString())
            ->whereIn('employee_id', $employeeIds->all())
            ->orderBy('id')
            ->get(['id', 'employee_id', 'status', 'hours'])
            ->groupBy('employee_id')
            ->map(static fn ($rows) => $rows->last());
        if ($latest->count() !== $employeeIds->count()) {
            return false;
        }

        return $latest->every(
            static fn (object $row): bool => $row->status !== 'at_work'
                && ($row->hours === null || (float) $row->hours === 0.0),
        );
    }
}
