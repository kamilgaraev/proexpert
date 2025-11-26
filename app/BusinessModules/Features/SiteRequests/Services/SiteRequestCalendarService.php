<?php

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestCalendarEvent;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\CalendarEventTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с календарем заявок
 */
class SiteRequestCalendarService
{
    public function __construct(
        private readonly SiteRequestsModule $module
    ) {}

    /**
     * Создать событие в календаре
     */
    public function createCalendarEvent(SiteRequest $request): ?SiteRequestCalendarEvent
    {
        if (!$request->hasCalendarEvent()) {
            return null;
        }

        $eventData = $this->prepareEventData($request);

        $calendarEvent = SiteRequestCalendarEvent::create($eventData);

        // Интеграция с schedule-management (если активен)
        if ($this->hasScheduleManagement($request->organization_id)) {
            $this->syncWithScheduleManagement($request, $calendarEvent);
        }

        Log::info('site_request.calendar_event.created', [
            'request_id' => $request->id,
            'event_id' => $calendarEvent->id,
        ]);

        return $calendarEvent;
    }

    /**
     * Обновить событие в календаре
     */
    public function updateCalendarEvent(SiteRequest $request): ?SiteRequestCalendarEvent
    {
        $calendarEvent = SiteRequestCalendarEvent::where('site_request_id', $request->id)->first();

        if (!$calendarEvent) {
            return $this->createCalendarEvent($request);
        }

        if (!$request->hasCalendarEvent()) {
            $this->deleteCalendarEvent($request);
            return null;
        }

        $eventData = $this->prepareEventData($request);
        $calendarEvent->update($eventData);

        // Обновить в schedule-management
        if ($this->hasScheduleManagement($request->organization_id) && $calendarEvent->schedule_event_id) {
            $this->updateScheduleManagementEvent($calendarEvent);
        }

        Log::info('site_request.calendar_event.updated', [
            'request_id' => $request->id,
            'event_id' => $calendarEvent->id,
        ]);

        return $calendarEvent;
    }

    /**
     * Удалить событие из календаря
     */
    public function deleteCalendarEvent(SiteRequest $request): void
    {
        $calendarEvent = SiteRequestCalendarEvent::where('site_request_id', $request->id)->first();

        if ($calendarEvent) {
            // Удалить из schedule-management
            if ($this->hasScheduleManagement($request->organization_id) && $calendarEvent->schedule_event_id) {
                $this->deleteScheduleManagementEvent($calendarEvent);
            }

            $calendarEvent->delete();

            Log::info('site_request.calendar_event.deleted', [
                'request_id' => $request->id,
            ]);
        }
    }

    /**
     * Получить события для календаря
     */
    public function getCalendarEvents(
        int $organizationId,
        Carbon $startDate,
        Carbon $endDate,
        ?int $projectId = null,
        ?string $eventType = null
    ): Collection {
        $query = SiteRequestCalendarEvent::query()
            ->forOrganization($organizationId)
            ->inDateRange($startDate, $endDate)
            ->with(['siteRequest.project', 'siteRequest.user']);

        if ($projectId) {
            $query->forProject($projectId);
        }

        if ($eventType) {
            $query->ofType($eventType);
        }

        return $query->orderBy('start_date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Получить события на конкретную дату
     */
    public function getEventsOnDate(int $organizationId, Carbon $date, ?int $projectId = null): Collection
    {
        $query = SiteRequestCalendarEvent::query()
            ->forOrganization($organizationId)
            ->onDate($date)
            ->with(['siteRequest.project', 'siteRequest.user']);

        if ($projectId) {
            $query->forProject($projectId);
        }

        return $query->orderBy('start_time')
            ->get();
    }

    /**
     * Подготовить данные для события
     */
    private function prepareEventData(SiteRequest $request): array
    {
        $eventType = $this->determineEventType($request);

        return [
            'site_request_id' => $request->id,
            'organization_id' => $request->organization_id,
            'project_id' => $request->project_id,
            'event_type' => $eventType->value,
            'title' => $this->getCalendarEventTitle($request),
            'description' => $request->description,
            'color' => $this->getEventColor($request),
            'start_date' => $this->getStartDate($request),
            'end_date' => $this->getEndDate($request),
            'start_time' => $this->getStartTime($request),
            'end_time' => $this->getEndTime($request),
            'all_day' => $this->isAllDay($request),
        ];
    }

    /**
     * Определить тип события
     */
    private function determineEventType(SiteRequest $request): CalendarEventTypeEnum
    {
        return CalendarEventTypeEnum::fromRequestType($request->request_type);
    }

    /**
     * Получить заголовок события для календаря
     */
    public function getCalendarEventTitle(SiteRequest $request): string
    {
        return match($request->request_type) {
            SiteRequestTypeEnum::MATERIAL_REQUEST => $this->getMaterialEventTitle($request),
            SiteRequestTypeEnum::PERSONNEL_REQUEST => $this->getPersonnelEventTitle($request),
            SiteRequestTypeEnum::EQUIPMENT_REQUEST => $this->getEquipmentEventTitle($request),
            default => $request->title,
        };
    }

    /**
     * Заголовок для доставки материалов
     */
    private function getMaterialEventTitle(SiteRequest $request): string
    {
        $name = $request->material_name ?? 'Материалы';
        $quantity = $request->material_quantity;
        $unit = $request->material_unit;

        if ($quantity && $unit) {
            return "Доставка: {$name} - {$quantity} {$unit}";
        }

        return "Доставка: {$name}";
    }

    /**
     * Заголовок для работы персонала
     */
    private function getPersonnelEventTitle(SiteRequest $request): string
    {
        $type = $request->personnel_type?->label() ?? 'Персонал';
        $count = $request->personnel_count;

        if ($count) {
            return "{$type} - {$count} чел.";
        }

        return $type;
    }

    /**
     * Заголовок для аренды техники
     */
    private function getEquipmentEventTitle(SiteRequest $request): string
    {
        $type = $request->equipment_type ?? 'Техника';
        return "Аренда: {$type}";
    }

    /**
     * Получить цвет события
     */
    public function getEventColor(SiteRequest $request): string
    {
        // Срочные - всегда красные
        if ($request->priority === SiteRequestPriorityEnum::URGENT) {
            return '#F44336';
        }

        // Иначе по типу
        return match($request->request_type) {
            SiteRequestTypeEnum::MATERIAL_REQUEST => '#4CAF50',  // Зеленый
            SiteRequestTypeEnum::PERSONNEL_REQUEST => '#2196F3', // Синий
            SiteRequestTypeEnum::EQUIPMENT_REQUEST => '#FF9800', // Оранжевый
            default => '#9E9E9E',                                // Серый
        };
    }

    /**
     * Получить дату начала
     */
    private function getStartDate(SiteRequest $request): ?Carbon
    {
        return $request->getCalendarStartDate();
    }

    /**
     * Получить дату окончания
     */
    private function getEndDate(SiteRequest $request): ?Carbon
    {
        return $request->getCalendarEndDate();
    }

    /**
     * Получить время начала
     */
    private function getStartTime(SiteRequest $request): ?string
    {
        if ($request->delivery_time_from) {
            return $request->delivery_time_from;
        }
        return null;
    }

    /**
     * Получить время окончания
     */
    private function getEndTime(SiteRequest $request): ?string
    {
        if ($request->delivery_time_to) {
            return $request->delivery_time_to;
        }
        return null;
    }

    /**
     * Проверить, является ли событие на весь день
     */
    private function isAllDay(SiteRequest $request): bool
    {
        return $request->delivery_time_from === null;
    }

    /**
     * Проверить наличие модуля schedule-management
     */
    private function hasScheduleManagement(int $organizationId): bool
    {
        return $this->module->hasScheduleManagement($organizationId);
    }

    /**
     * Синхронизировать с модулем schedule-management
     */
    private function syncWithScheduleManagement(
        SiteRequest $request,
        SiteRequestCalendarEvent $calendarEvent
    ): void {
        try {
            $scheduleService = app(\App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService::class);

            $scheduleEvent = $scheduleService->createEvent(
                $request->project_id,
                $request->organization_id,
                [
                    'title' => $calendarEvent->title,
                    'description' => $calendarEvent->description,
                    'event_date' => $calendarEvent->start_date,
                    'end_date' => $calendarEvent->end_date,
                    'event_time' => $calendarEvent->start_time,
                    'is_all_day' => $calendarEvent->all_day,
                    'color' => $calendarEvent->color,
                    'event_type' => 'site_request',
                    'priority' => 'medium',
                    'status' => 'scheduled',
                ]
            );

            $calendarEvent->update(['schedule_event_id' => $scheduleEvent->id]);

            Log::info('site_request.schedule_management.synced', [
                'request_id' => $request->id,
                'schedule_event_id' => $scheduleEvent->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('site_request.schedule_management.sync_failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обновить событие в schedule-management
     */
    private function updateScheduleManagementEvent(SiteRequestCalendarEvent $calendarEvent): void
    {
        try {
            $scheduleService = app(\App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService::class);
            $event = \App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent::find($calendarEvent->schedule_event_id);

            if ($event) {
                $scheduleService->updateEvent($event, [
                    'title' => $calendarEvent->title,
                    'description' => $calendarEvent->description,
                    'event_date' => $calendarEvent->start_date,
                    'end_date' => $calendarEvent->end_date,
                    'event_time' => $calendarEvent->start_time,
                    'is_all_day' => $calendarEvent->all_day,
                    'color' => $calendarEvent->color,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('site_request.schedule_management.update_failed', [
                'calendar_event_id' => $calendarEvent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Удалить событие из schedule-management
     */
    private function deleteScheduleManagementEvent(SiteRequestCalendarEvent $calendarEvent): void
    {
        try {
            $scheduleService = app(\App\BusinessModules\Features\ScheduleManagement\Services\ProjectEventService::class);
            $event = \App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent::find($calendarEvent->schedule_event_id);

            if ($event) {
                $scheduleService->deleteEvent($event);
            }
        } catch (\Exception $e) {
            Log::warning('site_request.schedule_management.delete_failed', [
                'calendar_event_id' => $calendarEvent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Проверить конфликты в расписании
     */
    public function checkScheduleConflicts(SiteRequest $request): Collection
    {
        if (!$request->hasCalendarEvent()) {
            return collect();
        }

        $startDate = $request->getCalendarStartDate();
        $endDate = $request->getCalendarEndDate() ?? $startDate;

        return SiteRequestCalendarEvent::query()
            ->forProject($request->project_id)
            ->where('site_request_id', '!=', $request->id)
            ->inDateRange($startDate, $endDate)
            ->with('siteRequest')
            ->get();
    }

    /**
     * Экспорт событий в iCal формат
     */
    public function exportToICal(
        int $organizationId,
        Carbon $startDate,
        Carbon $endDate,
        ?int $projectId = null
    ): string {
        $events = $this->getCalendarEvents($organizationId, $startDate, $endDate, $projectId);

        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//ProHelper//Site Requests//RU\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "X-WR-CALNAME:Заявки с объекта\r\n";

        foreach ($events as $event) {
            $ical .= $this->formatEventToICal($event);
        }

        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Форматировать событие в iCal формат
     */
    private function formatEventToICal(SiteRequestCalendarEvent $event): string
    {
        $uid = "site-request-{$event->id}@prohelper.ru";
        $dtstart = $event->start_date->format('Ymd');
        $dtend = ($event->end_date ?? $event->start_date->addDay())->format('Ymd');

        if (!$event->all_day && $event->start_time) {
            $dtstart = $event->start_date->format('Ymd') . 'T' . str_replace(':', '', $event->start_time) . '00';
            if ($event->end_time) {
                $dtend = ($event->end_date ?? $event->start_date)->format('Ymd') . 'T' . str_replace(':', '', $event->end_time) . '00';
            }
        }

        $ical = "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:" . now()->format('Ymd\THis\Z') . "\r\n";
        $ical .= "DTSTART:{$dtstart}\r\n";
        $ical .= "DTEND:{$dtend}\r\n";
        $ical .= "SUMMARY:" . $this->escapeICal($event->title) . "\r\n";

        if ($event->description) {
            $ical .= "DESCRIPTION:" . $this->escapeICal($event->description) . "\r\n";
        }

        $ical .= "END:VEVENT\r\n";

        return $ical;
    }

    /**
     * Экранировать специальные символы для iCal
     */
    private function escapeICal(string $text): string
    {
        $text = str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
        return $text;
    }
}

