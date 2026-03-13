<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;

class MobileScheduleService
{
    public function build(User $user, ?int $projectId = null): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_schedule.errors.no_organization'));
        }

        $project = null;
        if ($projectId !== null) {
            $project = Project::query()
                ->where('organization_id', $organizationId)
                ->find($projectId);

            if (!$project) {
                throw new DomainException(trans_message('mobile_schedule.errors.project_not_found'));
            }
        }

        $baseQuery = ProjectEvent::query()
            ->where('organization_id', $organizationId);

        if ($projectId !== null) {
            $baseQuery->where('project_id', $projectId);
        }

        $today = Carbon::today();
        $upcomingTo = Carbon::today()->addDays(7);
        $activeStatuses = ['scheduled', 'in_progress'];

        $summary = [
            'project_id' => $project?->id,
            'project_name' => $project?->name,
            'today_count' => (clone $baseQuery)
                ->whereDate('event_date', $today)
                ->whereIn('status', $activeStatuses)
                ->count(),
            'upcoming_count' => (clone $baseQuery)
                ->whereBetween('event_date', [$today->toDateString(), $upcomingTo->toDateString()])
                ->whereIn('status', $activeStatuses)
                ->count(),
            'blocking_count' => (clone $baseQuery)
                ->whereBetween('event_date', [$today->toDateString(), $upcomingTo->toDateString()])
                ->whereIn('status', $activeStatuses)
                ->where('is_blocking', true)
                ->count(),
            'in_progress_count' => (clone $baseQuery)
                ->where('status', 'in_progress')
                ->count(),
        ];

        $events = (clone $baseQuery)
            ->with(['project:id,name'])
            ->whereBetween('event_date', [$today->toDateString(), $upcomingTo->toDateString()])
            ->whereIn('status', $activeStatuses)
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->limit(10)
            ->get();

        return [
            'summary' => $summary,
            'events' => $this->mapEvents($events),
        ];
    }

    public function buildWidget(User $user): array
    {
        $data = $this->build($user);
        $summary = $data['summary'];

        return [
            'description' => trans_message('mobile_dashboard.widgets.schedule.description', [
                'upcoming' => $summary['upcoming_count'],
                'blocking' => $summary['blocking_count'],
            ]),
            'badge' => $summary['upcoming_count'] > 0 ? (string) $summary['upcoming_count'] : null,
            'payload' => $data,
        ];
    }

    private function mapEvents(Collection $events): array
    {
        return $events->map(function (ProjectEvent $event): array {
            return [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'project_id' => $event->project_id,
                'project_name' => $event->project?->name,
                'event_type' => $event->event_type,
                'event_type_label' => $this->translateEventType($event->event_type),
                'status' => $event->status,
                'status_label' => $this->translateStatus($event->status),
                'priority' => $event->priority,
                'priority_label' => $this->translatePriority($event->priority),
                'event_date' => $event->event_date?->toDateString(),
                'event_time' => $event->event_time,
                'location' => $event->location,
                'is_blocking' => (bool) $event->is_blocking,
                'is_all_day' => (bool) $event->is_all_day,
            ];
        })->values()->all();
    }

    private function translateEventType(?string $type): string
    {
        if (!$type) {
            return trans_message('mobile_schedule.event_types.other');
        }

        return trans_message('mobile_schedule.event_types.' . $type);
    }

    private function translateStatus(?string $status): string
    {
        if (!$status) {
            return trans_message('mobile_schedule.statuses.scheduled');
        }

        return trans_message('mobile_schedule.statuses.' . $status);
    }

    private function translatePriority(?string $priority): string
    {
        if (!$priority) {
            return trans_message('mobile_schedule.priorities.normal');
        }

        return trans_message('mobile_schedule.priorities.' . $priority);
    }
}
