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

final class SafetyExposureZeroFillJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
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
                    ->exists()) {
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
}
