<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCalendarEventResource;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use function trans_message;

class SiteRequestCalendarController extends Controller
{
    public function __construct(
        private readonly SiteRequestCalendarService $calendarService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'project_id' => ['nullable', 'integer'],
                'event_type' => ['nullable', 'string'],
            ]);

            return AdminResponse::success(
                SiteRequestCalendarEventResource::collection(
                    $this->calendarService->getCalendarEvents(
                        (int) $request->attributes->get('current_organization_id'),
                        Carbon::parse($validated['start_date']),
                        Carbon::parse($validated['end_date']),
                        isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                        $validated['event_type'] ?? null
                    )
                )
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('site_requests.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[SiteRequestCalendarController.index] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('site_requests.calendar_load_error'), 500);
        }
    }

    public function byDate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date' => ['required', 'date'],
                'project_id' => ['nullable', 'integer'],
            ]);

            return AdminResponse::success(
                SiteRequestCalendarEventResource::collection(
                    $this->calendarService->getEventsOnDate(
                        (int) $request->attributes->get('current_organization_id'),
                        Carbon::parse($validated['date']),
                        isset($validated['project_id']) ? (int) $validated['project_id'] : null
                    )
                )
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('site_requests.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[SiteRequestCalendarController.byDate] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('site_requests.calendar_date_load_error'), 500);
        }
    }

    public function export(Request $request): Response
    {
        try {
            $validated = $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'project_id' => ['nullable', 'integer'],
            ]);

            $ical = $this->calendarService->exportToICal(
                (int) $request->attributes->get('current_organization_id'),
                Carbon::parse($validated['start_date']),
                Carbon::parse($validated['end_date']),
                isset($validated['project_id']) ? (int) $validated['project_id'] : null
            );

            return response($ical, 200, [
                'Content-Type' => 'text/calendar; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="site-requests-' . now()->format('Y-m-d') . '.ics"',
            ]);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('site_requests.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('[SiteRequestCalendarController.export] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('site_requests.calendar_export_error'), 500);
        }
    }
}
