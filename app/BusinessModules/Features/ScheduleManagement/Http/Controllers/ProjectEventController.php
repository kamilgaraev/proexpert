<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Controllers;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class ProjectEventController extends Controller
{
    public function __construct(
        private readonly ProjectEventService $eventService
    ) {
    }

    public function calendar(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'event_types' => 'sometimes|array',
                'event_types.*' => 'string',
                'statuses' => 'sometimes|array',
                'statuses.*' => 'string',
                'priorities' => 'sometimes|array',
                'priorities.*' => 'string',
                'is_blocking' => 'sometimes|boolean',
            ]);

            $events = $this->eventService->getCalendarEvents(
                $projectId,
                Carbon::parse($validated['start_date']),
                Carbon::parse($validated['end_date']),
                [
                    'event_types' => $validated['event_types'] ?? [],
                    'statuses' => $validated['statuses'] ?? [],
                    'priorities' => $validated['priorities'] ?? [],
                    'is_blocking' => $validated['is_blocking'] ?? null,
                ]
            );

            return AdminResponse::success($events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'start' => $event->getStartDateTimeAttribute()->toIso8601String(),
                    'end' => $event->getEndDateTimeAttribute()->toIso8601String(),
                    'allDay' => $event->is_all_day,
                    'color' => $event->event_color,
                    'type' => $event->event_type,
                    'status' => $event->status,
                    'priority' => $event->priority,
                    'is_blocking' => $event->is_blocking,
                    'location' => $event->location,
                    'icon' => $event->event_icon,
                    'related_task_id' => $event->related_task_id,
                    'participants' => $event->participants,
                ];
            })->values());
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('schedule_management.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'calendar',
                $e,
                $request,
                trans_message('schedule_management.calendar_load_error'),
                ['project_id' => $projectId]
            );
        }
    }

    public function index(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'per_page' => 'sometimes|integer|min:1|max:100',
                'event_type' => 'sometimes|string',
                'status' => 'sometimes|string',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date',
            ]);

            $events = $this->eventService->getPaginatedEvents($projectId, $validated['per_page'] ?? 15, [
                'event_type' => $validated['event_type'] ?? null,
                'status' => $validated['status'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ]);

            return AdminResponse::paginated(
                $events->items(),
                [
                    'current_page' => $events->currentPage(),
                    'from' => $events->firstItem(),
                    'last_page' => $events->lastPage(),
                    'path' => $events->path(),
                    'per_page' => $events->perPage(),
                    'to' => $events->lastItem(),
                    'total' => $events->total(),
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $events->url(1),
                    'last' => $events->url($events->lastPage()),
                    'prev' => $events->previousPageUrl(),
                    'next' => $events->nextPageUrl(),
                ]
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('schedule_management.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'index',
                $e,
                $request,
                trans_message('schedule_management.events_load_error'),
                ['project_id' => $projectId]
            );
        }
    }

    public function show(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            $event = ProjectEvent::with(['createdBy', 'project'])
                ->where('project_id', $projectId)
                ->where('organization_id', $organizationId)
                ->findOrFail($eventId);

            return AdminResponse::success($event);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('schedule_management.event_not_found'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'show',
                $e,
                $request,
                trans_message('schedule_management.event_load_error'),
                ['project_id' => $projectId, 'event_id' => $eventId]
            );
        }
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'event_type' => 'required|string|in:inspection,delivery,meeting,maintenance,weather,other',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'event_date' => 'required|date',
                'event_time' => 'nullable|date_format:H:i',
                'duration_minutes' => 'nullable|integer|min:1',
                'is_all_day' => 'sometimes|boolean',
                'end_date' => 'nullable|date|after_or_equal:event_date',
                'is_blocking' => 'sometimes|boolean',
                'priority' => 'sometimes|string|in:low,normal,high,critical',
                'status' => 'sometimes|string|in:scheduled,in_progress,completed,cancelled',
                'schedule_id' => 'nullable|integer',
                'related_task_id' => 'nullable|integer',
                'participants' => 'nullable|array',
                'participants.*' => 'integer|exists:users,id',
                'responsible_users' => 'nullable|array',
                'responsible_users.*' => 'integer|exists:users,id',
                'organizations' => 'nullable|array',
                'reminder_before_hours' => 'nullable|integer|min:1|max:720',
                'is_recurring' => 'sometimes|boolean',
                'recurrence_pattern' => 'nullable|string|in:daily,weekly,monthly',
                'recurrence_config' => 'nullable|array',
                'recurrence_until' => 'nullable|date',
                'notes' => 'nullable|string',
                'color' => 'nullable|string|max:7',
                'icon' => 'nullable|string|max:50',
            ]);

            $event = $this->eventService->createEvent($projectId, $organizationId, $validated);

            return AdminResponse::success(
                $event->load(['createdBy']),
                trans_message('schedule_management.event_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('schedule_management.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'store',
                $e,
                $request,
                trans_message('schedule_management.event_create_error'),
                ['project_id' => $projectId]
            );
        }
    }

    public function update(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $event = ProjectEvent::query()
                ->where('project_id', $projectId)
                ->where('organization_id', $organizationId)
                ->findOrFail($eventId);

            $validated = $request->validate([
                'event_type' => 'sometimes|string|in:inspection,delivery,meeting,maintenance,weather,other',
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'event_date' => 'sometimes|date',
                'event_time' => 'nullable|date_format:H:i',
                'duration_minutes' => 'nullable|integer|min:1',
                'is_all_day' => 'sometimes|boolean',
                'end_date' => 'nullable|date|after_or_equal:event_date',
                'is_blocking' => 'sometimes|boolean',
                'priority' => 'sometimes|string|in:low,normal,high,critical',
                'status' => 'sometimes|string|in:scheduled,in_progress,completed,cancelled',
                'schedule_id' => 'nullable|integer',
                'related_task_id' => 'nullable|integer',
                'participants' => 'nullable|array',
                'responsible_users' => 'nullable|array',
                'organizations' => 'nullable|array',
                'reminder_before_hours' => 'nullable|integer|min:1|max:720',
                'is_recurring' => 'sometimes|boolean',
                'recurrence_pattern' => 'nullable|string|in:daily,weekly,monthly',
                'recurrence_config' => 'nullable|array',
                'recurrence_until' => 'nullable|date',
                'notes' => 'nullable|string',
                'color' => 'nullable|string|max:7',
                'icon' => 'nullable|string|max:50',
            ]);

            return AdminResponse::success(
                $this->eventService->updateEvent($event, $validated)->load(['createdBy']),
                trans_message('schedule_management.event_updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('schedule_management.event_not_found'), Response::HTTP_NOT_FOUND);
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('schedule_management.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'update',
                $e,
                $request,
                trans_message('schedule_management.event_update_error'),
                ['project_id' => $projectId, 'event_id' => $eventId]
            );
        }
    }

    public function destroy(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $event = ProjectEvent::query()
                ->where('project_id', $projectId)
                ->where('organization_id', $organizationId)
                ->findOrFail($eventId);

            $this->eventService->deleteEvent($event, (bool) $request->input('delete_recurring', false));

            return AdminResponse::success(null, trans_message('schedule_management.event_deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('schedule_management.event_not_found'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'destroy',
                $e,
                $request,
                trans_message('schedule_management.event_delete_error'),
                ['project_id' => $projectId, 'event_id' => $eventId]
            );
        }
    }

    public function upcoming(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'days' => 'sometimes|integer|min:1|max:30',
            ]);

            return AdminResponse::success(
                $this->eventService->getUpcomingEvents($projectId, $validated['days'] ?? 7)
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('schedule_management.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'upcoming',
                $e,
                $request,
                trans_message('schedule_management.upcoming_load_error'),
                ['project_id' => $projectId]
            );
        }
    }

    public function today(Request $request, int $projectId): JsonResponse
    {
        try {
            return AdminResponse::success($this->eventService->getTodayEvents($projectId));
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'today',
                $e,
                $request,
                trans_message('schedule_management.today_load_error'),
                ['project_id' => $projectId]
            );
        }
    }

    public function statistics(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            return AdminResponse::success(
                $this->eventService->getEventsStatistics(
                    $projectId,
                    Carbon::parse($validated['start_date']),
                    Carbon::parse($validated['end_date'])
                )
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('schedule_management.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'statistics',
                $e,
                $request,
                trans_message('schedule_management.event_statistics_load_error'),
                ['project_id' => $projectId]
            );
        }
    }

    public function checkConflicts(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $event = ProjectEvent::query()
                ->where('project_id', $projectId)
                ->where('organization_id', $organizationId)
                ->findOrFail($eventId);

            return AdminResponse::success($this->eventService->checkEventConflicts($event));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('schedule_management.event_not_found'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'checkConflicts',
                $e,
                $request,
                trans_message('schedule_management.event_conflicts_load_error'),
                ['project_id' => $projectId, 'event_id' => $eventId]
            );
        }
    }

    private function handleUnexpectedError(
        string $action,
        \Throwable $e,
        Request $request,
        string $message,
        array $context = []
    ): JsonResponse {
        Log::error("[ProjectEventController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            ...$context,
        ]);

        return AdminResponse::error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
