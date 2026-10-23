<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class ProjectEventSeries extends Model
{
    protected $table = 'project_event_series';

    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer'];

    public function segments(): HasMany
    {
        return $this->hasMany(ProjectEventSeriesSegment::class, 'series_id')->orderBy('revision');
    }

    public function definitionAt(int $position): array
    {
        $plan = [];
        $rules = ['enabled' => true, 'enabled_from_position' => 0, 'pattern' => null, 'until' => null, 'configuration' => null];
        foreach ($this->segments as $segment) {
            if ($segment->from_position > $position) {
                continue;
            }
            if ($segment->revision === 0) {
                $plan = $segment->template;
            }
            $oldDate = Carbon::parse($plan['event_date'], 'UTC');
            $date = $oldDate->copy();
            if ($segment->pattern !== null) {
                $anchor = Carbon::parse($segment->anchor_date->toDateString(), 'UTC');
                $offset = $position - $segment->from_position;
                $date = match ($segment->pattern) {
                    'daily' => $anchor->addDays($offset),
                    'weekly' => $anchor->addWeeks($offset),
                    'monthly' => $anchor->addMonthsNoOverflow($offset),
                    default => $anchor,
                };
                $rules['pattern'] = $segment->pattern;
            } elseif (isset($segment->interval['shift_days'])) {
                $date->addDays($segment->interval['shift_days']);
            }
            $span = empty($plan['end_date']) ? null : (int) $oldDate->diffInDays(Carbon::parse($plan['end_date'], 'UTC'), false);
            if (array_key_exists('end_span_days', $segment->interval ?? [])) {
                $span = $segment->interval['end_span_days'];
            }
            if ($segment->revision !== 0) {
                $plan = array_replace($plan, $segment->template);
            }
            $plan['event_date'] = $date->toDateString();
            $plan['end_date'] = $span === null ? null : $date->copy()->addDays($span)->toDateString();
            if ($segment->until !== null) {
                $rules['until'] = $segment->until->toDateString();
            }
            if ($segment->enabled !== null) {
                $rules['enabled'] = $segment->enabled;
                $rules['enabled_from_position'] = $segment->from_position;
            }
            if ($segment->configuration !== null) {
                $rules['configuration'] = $segment->configuration;
            }
        }

        return ['plan' => $plan, ...$rules];
    }
}
