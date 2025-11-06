<?php

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectEventService
{
    /**
     * Получить события для календарного представления
     */
    public function getCalendarEvents(
        int $projectId,
        Carbon $startDate,
        Carbon $endDate,
        array $filters = []
    ): Collection {
        $query = ProjectEvent::query()
            ->with(['createdBy', 'relatedTask', 'schedule'])
            ->forProject($projectId)
            ->inDateRange($startDate, $endDate);

        // Применяем фильтры
        if (!empty($filters['event_types'])) {
            $query->whereIn('event_type', $filters['event_types']);
        }

        if (!empty($filters['statuses'])) {
            $query->whereIn('status', $filters['statuses']);
        }

        if (!empty($filters['priorities'])) {
            $query->whereIn('priority', $filters['priorities']);
        }

        if (isset($filters['is_blocking'])) {
            $query->where('is_blocking', $filters['is_blocking']);
        }

        return $query->orderBy('event_date')
            ->orderBy('event_time')
            ->get();
    }

    /**
     * Получить события с пагинацией
     */
    public function getPaginatedEvents(
        int $projectId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = ProjectEvent::query()
            ->with(['createdBy', 'relatedTask', 'schedule'])
            ->forProject($projectId);

        // Применяем фильтры
        if (!empty($filters['event_type'])) {
            $query->byType($filters['event_type']);
        }

        if (!empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('event_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('event_date', '<=', $filters['date_to']);
        }

        return $query->orderBy('event_date', 'desc')
            ->orderBy('event_time', 'desc')
            ->paginate($perPage);
    }

    /**
     * Создать событие
     */
    public function createEvent(int $projectId, int $organizationId, array $data): ProjectEvent
    {
        // Валидация данных
        $this->validateEventData($data);

        // Создаем событие
        $event = DB::transaction(function () use ($projectId, $organizationId, $data) {
            $event = ProjectEvent::create([
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'created_by_user_id' => auth()->id(),
                ...$data,
            ]);

            // Если событие повторяющееся, создаем экземпляры
            if ($event->is_recurring && isset($data['recurrence_until'])) {
                $until = Carbon::parse($data['recurrence_until']);
                $recurringEvents = $event->generateRecurringEvents($until);
                
                foreach ($recurringEvents as $recurringEvent) {
                    $recurringEvent->save();
                }
            }

            // Планируем напоминание
            if ($event->reminder_before_hours) {
                $event->scheduleReminder();
            }

            return $event;
        });

        \Log::info('schedule.event.created', [
            'event_id' => $event->id,
            'project_id' => $projectId,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date,
        ]);

        return $event;
    }

    /**
     * Обновить событие
     */
    public function updateEvent(ProjectEvent $event, array $data): ProjectEvent
    {
        $this->validateEventData($data, $event);

        DB::transaction(function () use ($event, $data) {
            $event->update($data);

            // Если изменили дату и есть напоминание, пересоздаем его
            if (isset($data['event_date']) && $event->reminder_before_hours && !$event->reminder_sent) {
                $event->scheduleReminder();
            }

            // Если это повторяющееся событие и изменили параметры повторения
            if ($event->is_recurring && 
                (isset($data['recurrence_pattern']) || isset($data['recurrence_config']))) {
                // Удаляем старые повторяющиеся события
                $event->recurringChildren()->delete();
                
                // Создаем новые
                if (isset($data['recurrence_until'])) {
                    $until = Carbon::parse($data['recurrence_until']);
                    $recurringEvents = $event->generateRecurringEvents($until);
                    
                    foreach ($recurringEvents as $recurringEvent) {
                        $recurringEvent->save();
                    }
                }
            }
        });

        \Log::info('schedule.event.updated', [
            'event_id' => $event->id,
            'project_id' => $event->project_id,
        ]);

        return $event->fresh();
    }

    /**
     * Удалить событие
     */
    public function deleteEvent(ProjectEvent $event, bool $deleteRecurring = false): bool
    {
        return DB::transaction(function () use ($event, $deleteRecurring) {
            // Если это родительское повторяющееся событие
            if ($event->is_recurring && $deleteRecurring) {
                $event->recurringChildren()->delete();
            }

            $deleted = $event->delete();

            \Log::info('schedule.event.deleted', [
                'event_id' => $event->id,
                'project_id' => $event->project_id,
                'delete_recurring' => $deleteRecurring,
            ]);

            return $deleted;
        });
    }

    /**
     * Получить ближайшие события
     */
    public function getUpcomingEvents(int $projectId, int $days = 7): Collection
    {
        return ProjectEvent::query()
            ->with(['createdBy', 'relatedTask'])
            ->forProject($projectId)
            ->upcoming($days)
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();
    }

    /**
     * Получить события сегодня
     */
    public function getTodayEvents(int $projectId): Collection
    {
        return ProjectEvent::query()
            ->with(['createdBy', 'relatedTask'])
            ->forProject($projectId)
            ->whereDate('event_date', today())
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->orderBy('event_time')
            ->get();
    }

    /**
     * Получить блокирующие события в диапазоне дат
     */
    public function getBlockingEvents(
        int $projectId,
        Carbon $startDate,
        Carbon $endDate
    ): Collection {
        return ProjectEvent::query()
            ->forProject($projectId)
            ->blocking()
            ->inDateRange($startDate, $endDate)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->orderBy('event_date')
            ->get();
    }

    /**
     * Проверить конфликты событий
     */
    public function checkEventConflicts(ProjectEvent $event): array
    {
        $conflicts = ProjectEvent::query()
            ->forProject($event->project_id)
            ->where('id', '!=', $event->id)
            ->where('is_blocking', true)
            ->inDateRange(
                $event->event_date,
                $event->end_date ?? $event->event_date
            )
            ->get();

        return [
            'has_conflicts' => $conflicts->isNotEmpty(),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Получить статистику событий
     */
    public function getEventsStatistics(int $projectId, Carbon $startDate, Carbon $endDate): array
    {
        $events = ProjectEvent::query()
            ->forProject($projectId)
            ->inDateRange($startDate, $endDate)
            ->get();

        return [
            'total' => $events->count(),
            'by_type' => $events->groupBy('event_type')->map->count(),
            'by_status' => $events->groupBy('status')->map->count(),
            'by_priority' => $events->groupBy('priority')->map->count(),
            'blocking_count' => $events->where('is_blocking', true)->count(),
            'upcoming_count' => $events->where('event_date', '>=', now()->toDateString())->count(),
        ];
    }

    /**
     * Отправить напоминания о предстоящих событиях
     */
    public function sendReminders(): int
    {
        $events = ProjectEvent::query()
            ->needsReminder()
            ->get();

        $sentCount = 0;

        foreach ($events as $event) {
            $reminderTime = $event->getStartDateTimeAttribute()
                ->subHours($event->reminder_before_hours);

            if (now()->gte($reminderTime)) {
                // Здесь логика отправки уведомлений
                // dispatch(new SendEventReminderJob($event));
                
                $event->markReminderSent();
                $sentCount++;
            }
        }

        return $sentCount;
    }

    /**
     * Валидация данных события
     */
    private function validateEventData(array $data, ?ProjectEvent $event = null): void
    {
        // Проверка обязательных полей
        if (!isset($data['title']) || empty($data['title'])) {
            throw new \InvalidArgumentException('Название события обязательно');
        }

        if (!isset($data['event_date'])) {
            throw new \InvalidArgumentException('Дата события обязательна');
        }

        // Проверка дат
        $eventDate = Carbon::parse($data['event_date']);
        if (isset($data['end_date'])) {
            $endDate = Carbon::parse($data['end_date']);
            if ($endDate->lt($eventDate)) {
                throw new \InvalidArgumentException('Дата окончания не может быть раньше даты начала');
            }
        }

        // Проверка длительности
        if (isset($data['duration_minutes']) && $data['duration_minutes'] < 0) {
            throw new \InvalidArgumentException('Длительность не может быть отрицательной');
        }
    }
}

