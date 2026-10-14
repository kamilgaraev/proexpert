<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Sources;

use App\BusinessModules\Features\TimeTracking\Support\WorkTimeInterval;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;

/** Public source adapter for already-authorized HR orchestration; tenant and worker ownership are enforced here. */
final class ApprovedWorkTimeSourceReader
{
    public function read(int $organizationId, int $userId, string $from, string $to, ?int $projectId = null): array
    {
        if ($organizationId <= 0 || ! User::query()->whereKey($userId)->whereHas('organizations', fn ($query) => $query->where('organizations.id', $organizationId))->exists()) {
            throw new DomainException(trans_message('time_tracking.mobile.errors.permission_denied'));
        }
        $entries = TimeEntry::query()->where('organization_id', $organizationId)->where('user_id', $userId)
            ->where('status', 'approved')->whereBetween('work_date', [CarbonImmutable::parse($from)->subDay()->toDateString(), CarbonImmutable::parse($to)->addDay()->toDateString()])
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))->orderBy('id')->get();

        return $entries->map(static function (TimeEntry $entry): array {
            $fact = [
                'source_type' => 'time_entry', 'source_id' => (int) $entry->id,
                'organization_id' => (int) $entry->organization_id, 'user_id' => (int) $entry->user_id,
                'project_id' => (int) $entry->project_id, 'status' => 'approved',
                'employment_contract_id' => isset($entry->custom_fields['employment_contract_id']) ? (int) $entry->custom_fields['employment_contract_id'] : null,
                'source_revision' => $entry->updated_at?->toIso8601String(),
                'work_date' => $entry->work_date->format('Y-m-d'),
                'clock_source' => $entry->custom_fields['time_tracking_interval']['clock_source'] ?? 'unknown',
                'start_time' => $entry->getRawOriginal('start_time'), 'end_time' => $entry->getRawOriginal('end_time'),
                'start_at' => $entry->start_at?->utc()->format('Y-m-d\\TH:i:s.uP'), 'end_at' => $entry->end_at?->utc()->format('Y-m-d\\TH:i:s.uP'),
                'timezone' => $entry->timezone, 'timezone_requires_reconciliation' => $entry->timezone === null || (bool) $entry->timezone_requires_reconciliation, 'break_time' => (float) ($entry->break_time ?? 0),
                'break_intervals' => $entry->break_intervals ?? [], 'net_minutes' => $entry->net_minutes, 'net_microseconds' => $entry->net_microseconds,
                'hours_worked' => $entry->hours_worked === null ? null : (float) $entry->hours_worked,
                'requires_reconciliation' => true,
            ];
            if ($fact['start_at'] !== null && $fact['end_at'] !== null && $fact['timezone'] !== null) {
                try {
                    $interval = WorkTimeInterval::fromInput($fact);
                    $fact['requires_reconciliation'] = $interval->requiresReconciliation;
                    $fact['net_minutes'] = $interval->netMinutes();
                    $fact['net_microseconds'] = $interval->netMicroseconds();
                } catch (\Throwable $exception) {
                    $fact['requires_reconciliation'] = true;
                }
            }

            return $fact;
        })->all();
    }
}
