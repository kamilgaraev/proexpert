<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class ProjectEventController extends Controller
{
    public function __construct(
        private readonly ProjectEventService $eventService
    ) {}

    /**
     * Получить события в виде календаря
     * 
     * @group Schedule Events
     */
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

            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);

            $filters = [
                'event_types' => $validated['event_types'] ?? [],
                'statuses' => $validated['statuses'] ?? [],
                'priorities' => $validated['priorities'] ?? [],
                'is_blocking' => $validated['is_blocking'] ?? null,
            ];

            $events = $this->eventService->getCalendarEvents(
                $projectId,
                $startDate,
                $endDate,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => $events->map(function ($event) {
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
                }),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('schedule.event.calendar.error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить календарь событий',
            ], 500);
        }
    }

    /**
     * Список событий с пагинацией
     * 
     * @group Schedule Events
     */
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

            $perPage = $validated['per_page'] ?? 15;
            $filters = [
                'event_type' => $validated['event_type'] ?? null,
                'status' => $validated['status'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
            ];

            $events = $this->eventService->getPaginatedEvents($projectId, $perPage, $filters);

            return response()->json([
                'success' => true,
                'data' => $events->items(),
                'meta' => [
                    'current_page' => $events->currentPage(),
                    'last_page' => $events->lastPage(),
                    'per_page' => $events->perPage(),
                    'total' => $events->total(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('schedule.event.index.error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить список событий',
            ], 500);
        }
    }

    /**
     * Показать конкретное событие
     * 
     * @group Schedule Events
     */
    public function show(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $event = ProjectEvent::with(['createdBy', 'relatedTask', 'schedule', 'project'])
                ->where('project_id', $projectId)
                ->where('organization_id', $organizationId)
                ->findOrFail($eventId);

            return response()->json([
                'success' => true,
                'data' => $event,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Событие не найдено',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('schedule.event.show.error', [
                'project_id' => $projectId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить событие',
            ], 500);
        }
    }

    /**
     * Создать новое событие
     * 
     * @group Schedule Events
     */
    public function store(Request $request, int $projectId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

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
                'schedule_id' => 'nullable|integer|exists:project_schedules,id',
                'related_task_id' => 'nullable|integer|exists:schedule_tasks,id',
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

            return response()->json([
                'success' => true,
                'message' => 'Событие успешно создано',
                'data' => $event->load(['createdBy', 'relatedTask']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('schedule.event.store.error', [
                'project_id' => $projectId,
                'data' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать событие',
            ], 500);
        }
    }

    /**
     * Обновить событие
     * 
     * @group Schedule Events
     */
    public function update(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $event = ProjectEvent::where('project_id', $projectId)
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
                'schedule_id' => 'nullable|integer|exists:project_schedules,id',
                'related_task_id' => 'nullable|integer|exists:schedule_tasks,id',
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

            $updatedEvent = $this->eventService->updateEvent($event, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Событие успешно обновлено',
                'data' => $updatedEvent->load(['createdBy', 'relatedTask']),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Событие не найдено',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка валидации',
                'errors' => $e->errors(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('schedule.event.update.error', [
                'project_id' => $projectId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить событие',
            ], 500);
        }
    }

    /**
     * Удалить событие
     * 
     * @group Schedule Events
     */
    public function destroy(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $event = ProjectEvent::where('project_id', $projectId)
                ->where('organization_id', $organizationId)
                ->findOrFail($eventId);

            $deleteRecurring = $request->input('delete_recurring', false);

            $this->eventService->deleteEvent($event, $deleteRecurring);

            return response()->json([
                'success' => true,
                'message' => 'Событие успешно удалено',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Событие не найдено',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('schedule.event.destroy.error', [
                'project_id' => $projectId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить событие',
            ], 500);
        }
    }

    /**
     * Получить ближайшие события
     * 
     * @group Schedule Events
     */
    public function upcoming(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'days' => 'sometimes|integer|min:1|max:30',
            ]);

            $days = $validated['days'] ?? 7;

            $events = $this->eventService->getUpcomingEvents($projectId, $days);

            return response()->json([
                'success' => true,
                'data' => $events,
            ]);
        } catch (\Exception $e) {
            \Log::error('schedule.event.upcoming.error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить ближайшие события',
            ], 500);
        }
    }

    /**
     * Получить события сегодня
     * 
     * @group Schedule Events
     */
    public function today(Request $request, int $projectId): JsonResponse
    {
        try {
            $events = $this->eventService->getTodayEvents($projectId);

            return response()->json([
                'success' => true,
                'data' => $events,
            ]);
        } catch (\Exception $e) {
            \Log::error('schedule.event.today.error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить события сегодня',
            ], 500);
        }
    }

    /**
     * Получить статистику событий
     * 
     * @group Schedule Events
     */
    public function statistics(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            $startDate = Carbon::parse($validated['start_date']);
            $endDate = Carbon::parse($validated['end_date']);

            $statistics = $this->eventService->getEventsStatistics($projectId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);
        } catch (\Exception $e) {
            \Log::error('schedule.event.statistics.error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статистику',
            ], 500);
        }
    }

    /**
     * Проверить конфликты события
     * 
     * @group Schedule Events
     */
    public function checkConflicts(Request $request, int $projectId, int $eventId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $event = ProjectEvent::where('project_id', $projectId)
                ->where('organization_id', $organizationId)
                ->findOrFail($eventId);

            $result = $this->eventService->checkEventConflicts($event);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Событие не найдено',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('schedule.event.check_conflicts.error', [
                'project_id' => $projectId,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось проверить конфликты',
            ], 500);
        }
    }
}

