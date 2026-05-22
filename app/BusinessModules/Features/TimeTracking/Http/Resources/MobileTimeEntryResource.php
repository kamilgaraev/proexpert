<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Http\Resources;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\TimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeEntry */
final class MobileTimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TimeEntry $entry */
        $entry = $this->resource;
        $availableActions = $this->availableActions($request, $entry);

        return [
            'id' => $entry->id,
            'organization_id' => $entry->organization_id,
            'user_id' => $entry->user_id,
            'project_id' => $entry->project_id,
            'project_label' => $entry->project?->name,
            'work_type_id' => $entry->work_type_id,
            'work_type_label' => $entry->workType?->name,
            'task_id' => $entry->task_id,
            'task_label' => $entry->task?->name,
            'work_date' => $entry->work_date?->toDateString(),
            'start_time' => $this->time($entry->start_time),
            'end_time' => $this->time($entry->end_time),
            'hours_worked' => $entry->hours_worked !== null ? (float) $entry->hours_worked : null,
            'break_time' => $entry->break_time !== null ? (float) $entry->break_time : null,
            'title' => $entry->title,
            'description' => $entry->description,
            'status' => $entry->status,
            'status_label' => trans_message("time_tracking.mobile.statuses.{$entry->status}"),
            'is_active_timer' => $this->isActiveTimer($entry),
            'is_billable' => (bool) $entry->is_billable,
            'location' => $entry->location,
            'notes' => $entry->notes,
            'approved_by_user_id' => $entry->approved_by_user_id,
            'approved_by_label' => $entry->approvedBy?->name,
            'approved_at' => $entry->approved_at?->toIso8601String(),
            'rejection_reason' => $entry->rejection_reason,
            'corrections' => $this->corrections($entry),
            'available_actions' => $availableActions,
            'approval_summary' => [
                'status' => $entry->status,
                'status_label' => trans_message("time_tracking.mobile.statuses.{$entry->status}"),
                'approved_by_label' => $entry->approvedBy?->name,
                'approved_at' => $entry->approved_at?->toIso8601String(),
                'rejection_reason' => $entry->rejection_reason,
            ],
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    private function availableActions(Request $request, TimeEntry $entry): array
    {
        $user = $request->user();
        if (!$user) {
            return [];
        }

        $authorization = app(AuthorizationService::class);
        $organizationId = (int) $entry->organization_id;
        $canEdit = $authorization->can($user, 'time_tracking.edit', ['organization_id' => $organizationId]);
        $canSubmit = $authorization->can($user, 'time_tracking.submit', ['organization_id' => $organizationId]);

        if ($this->isActiveTimer($entry)) {
            return $canEdit ? ['stop'] : [];
        }

        $actions = [];
        if ($canSubmit && in_array($entry->status, ['draft', 'rejected'], true) && $entry->hours_worked !== null) {
            $actions[] = 'submit';
        }

        if ($canEdit && $canSubmit && $entry->status === 'rejected') {
            $actions[] = 'correction';
        }

        return $actions;
    }

    private function isActiveTimer(TimeEntry $entry): bool
    {
        $state = $this->mobileState($entry);

        return ($state['active_timer'] ?? false) === true
            && $entry->status === 'draft'
            && $entry->start_time !== null
            && $entry->end_time === null;
    }

    private function corrections(TimeEntry $entry): array
    {
        $state = $this->mobileState($entry);
        $corrections = $state['corrections'] ?? [];

        return is_array($corrections) ? array_values($corrections) : [];
    }

    private function mobileState(TimeEntry $entry): array
    {
        $customFields = is_array($entry->custom_fields) ? $entry->custom_fields : [];
        $state = $customFields['mobile_time_tracking'] ?? [];

        return is_array($state) ? $state : [];
    }

    private function time(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('H:i');
        }

        if (is_string($value) && trim($value) !== '') {
            return substr($value, 0, 5);
        }

        return null;
    }
}
