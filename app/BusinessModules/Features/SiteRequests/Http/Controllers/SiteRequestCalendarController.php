<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCalendarEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Carbon\Carbon;

/**
 * Контроллер календаря заявок
 */
class SiteRequestCalendarController extends Controller
{
    public function __construct(
        private readonly SiteRequestCalendarService $calendarService
    ) {}

    /**
     * Получить события для календаря
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'project_id' => ['nullable', 'integer'],
                'event_type' => ['nullable', 'string'],
            ]);

            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $projectId = $request->input('project_id');
            $eventType = $request->input('event_type');

            $events = $this->calendarService->getCalendarEvents(
                $organizationId,
                $startDate,
                $endDate,
                $projectId,
                $eventType
            );

            return response()->json([
                'success' => true,
                'data' => SiteRequestCalendarEventResource::collection($events),
                'count' => $events->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.calendar.index.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить события календаря',
            ], 500);
        }
    }

    /**
     * Получить события на конкретную дату
     */
    public function byDate(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $request->validate([
                'date' => ['required', 'date'],
                'project_id' => ['nullable', 'integer'],
            ]);

            $date = Carbon::parse($request->input('date'));
            $projectId = $request->input('project_id');

            $events = $this->calendarService->getEventsOnDate($organizationId, $date, $projectId);

            return response()->json([
                'success' => true,
                'data' => SiteRequestCalendarEventResource::collection($events),
                'date' => $date->format('Y-m-d'),
                'count' => $events->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.calendar.by_date.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить события',
            ], 500);
        }
    }

    /**
     * Экспорт календаря в iCal формат
     */
    public function export(Request $request): Response
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');

            $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'project_id' => ['nullable', 'integer'],
            ]);

            $startDate = Carbon::parse($request->input('start_date'));
            $endDate = Carbon::parse($request->input('end_date'));
            $projectId = $request->input('project_id');

            $ical = $this->calendarService->exportToICal(
                $organizationId,
                $startDate,
                $endDate,
                $projectId
            );

            $filename = 'site-requests-' . now()->format('Y-m-d') . '.ics';

            return response($ical, 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        } catch (\Exception $e) {
            \Log::error('site_requests.calendar.export.error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось экспортировать календарь',
            ], 500);
        }
    }
}

