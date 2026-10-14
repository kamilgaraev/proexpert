<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;

/** Contract calendars classify confirmed instants; provisional legacy clocks need reconciliation. */
final class TimekeepingFactTimezone
{
    public function normalize(array $fact, array $relations): array
    {
        if (($fact['clock_source'] ?? null) === 'unknown') {
            return array_replace($fact, ['timezone_requires_reconciliation' => true]);
        }
        $explicit = isset($fact['employment_contract_id']) ? (int) $fact['employment_contract_id'] : null;
        $candidates = [];
        foreach ($relations as $relation) {
            if (($relation['status'] ?? null) !== 'active' || ($relation['relation_type'] ?? null) !== 'employment'
                || empty($relation['employment_mode']) || empty($relation['timezone'])
                || ($explicit !== null && (int) $relation['id'] !== $explicit)) {
                continue;
            }
            $input = $this->forRelation($fact, $relation);
            $date = (string) ($input['work_date'] ?? '');
            if ($relation['start_date'] <= $date && (empty($relation['end_date']) || $relation['end_date'] >= $date)) {
                $candidates[] = $input;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : $fact;
    }

    public function forRelation(array $fact, array $relation): array
    {
        $input = $fact;
        $input['source_work_date'] = $fact['source_work_date'] ?? $fact['work_date'] ?? null;
        $absolute = $this->absolute($fact['start_at'] ?? null) && $this->absolute($fact['end_at'] ?? null);
        $clock = $fact['clock_source'] ?? ($absolute ? 'absolute' : 'legacy_local');
        if ($clock === 'unknown' || ($clock === 'absolute' && ! $absolute)) {
            return array_replace($input, ['timezone_requires_reconciliation' => true]);
        }
        try {
            if ($clock === 'legacy_local' && ! empty($fact['timezone_requires_reconciliation'])) {
                // Guessed ISO values cannot become evidence by changing their timezone label.
                unset($input['start_at'], $input['end_at']);
                $input['work_date'] = $input['source_work_date'];
            } elseif (! $absolute) {
                $source = WorkTimeInterval::fromInput($fact);
                if ($source->timezoneRequiresReconciliation) {
                    return array_replace($input, ['timezone_requires_reconciliation' => true]);
                }
                $input['start_at'] = $source->start->format(DATE_ATOM);
                $input['end_at'] = $source->end->format(DATE_ATOM);
                unset($input['work_date']);
            } else {
                // Source work_date belongs to the source timezone, not the contract calendar.
                unset($input['work_date']);
            }
            $input['timezone'] = $relation['timezone'];
            $input['timezone_requires_reconciliation'] = false;
            $interval = WorkTimeInterval::fromInput($input);
            $input['work_date'] = $interval->start->setTimezone(new \DateTimeZone($interval->timezone))->format('Y-m-d');
        } catch (\Throwable) {
            $input['timezone_requires_reconciliation'] = true;
            $input['work_date'] = $input['source_work_date'];
        }

        return $input;
    }

    private function absolute(mixed $timestamp): bool
    {
        return is_string($timestamp) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp) === 1;
    }
}
