<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicationError;
use App\Services\ErrorTracking\ErrorTrackingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ErrorTrackingController extends Controller
{
    public function __construct(
        private readonly ErrorTrackingService $errorTrackingService
    ) {}

    /**
     * Получить список последних ошибок
     * 
     * @group Error Tracking
     * @authenticated
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'organization_id' => $request->input('organization_id'),
                'status' => $request->input('status', 'unresolved'),
                'severity' => $request->input('severity'),
                'module' => $request->input('module'),
            ];

            // Удалить null значения
            $filters = array_filter($filters, fn($value) => $value !== null);

            $limit = min($request->input('limit', 50), 100);
            
            $errors = $this->errorTrackingService->getRecent($limit, $filters);
            
            return response()->json([
                'success' => true,
                'data' => $errors->map(function ($error) {
                    return [
                        'id' => $error->id,
                        'error_hash' => $error->error_hash,
                        'error_group' => $error->error_group,
                        'exception_class' => $error->exception_class,
                        'message' => $error->message,
                        'short_message' => $error->short_message,
                        'file' => $error->short_file,
                        'line' => $error->line,
                        'module' => $error->module,
                        'organization_id' => $error->organization_id,
                        'user_id' => $error->user_id,
                        'occurrences' => $error->occurrences,
                        'severity' => $error->severity,
                        'status' => $error->status,
                        'first_seen_at' => $error->first_seen_at->toIso8601String(),
                        'last_seen_at' => $error->last_seen_at->toIso8601String(),
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            \Log::error('error_tracking.index.failed', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить ошибки',
            ], 500);
        }
    }

    /**
     * Получить детали конкретной ошибки
     * 
     * @group Error Tracking
     * @authenticated
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $error = ApplicationError::with(['organization', 'user'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $error->id,
                    'error_hash' => $error->error_hash,
                    'error_group' => $error->error_group,
                    'exception_class' => $error->exception_class,
                    'message' => $error->message,
                    'file' => $error->file,
                    'line' => $error->line,
                    'stack_trace' => $error->stack_trace,
                    'module' => $error->module,
                    'url' => $error->url,
                    'method' => $error->method,
                    'ip' => $error->ip,
                    'user_agent' => $error->user_agent,
                    'context' => $error->context,
                    'organization_id' => $error->organization_id,
                    'organization_name' => $error->organization?->name,
                    'user_id' => $error->user_id,
                    'user_name' => $error->user?->name,
                    'occurrences' => $error->occurrences,
                    'severity' => $error->severity,
                    'status' => $error->status,
                    'first_seen_at' => $error->first_seen_at->toIso8601String(),
                    'last_seen_at' => $error->last_seen_at->toIso8601String(),
                    'created_at' => $error->created_at->toIso8601String(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка не найдена',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('error_tracking.show.failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить детали ошибки',
            ], 500);
        }
    }

    /**
     * Получить статистику ошибок
     * 
     * @group Error Tracking
     * @authenticated
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $filters = [
                'organization_id' => $request->input('organization_id'),
                'module' => $request->input('module'),
                'status' => $request->input('status'),
                'severity' => $request->input('severity'),
                'days' => $request->input('days', 7),
            ];

            // Удалить null значения
            $filters = array_filter($filters, fn($value) => $value !== null);

            $statistics = $this->errorTrackingService->getStatistics($filters);
            
            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);
        } catch (\Exception $e) {
            \Log::error('error_tracking.statistics.failed', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить статистику',
            ], 500);
        }
    }

    /**
     * Получить топ ошибок
     * 
     * @group Error Tracking
     * @authenticated
     */
    public function top(Request $request): JsonResponse
    {
        try {
            $filters = [
                'organization_id' => $request->input('organization_id'),
                'days' => $request->input('days', 7),
            ];

            $limit = min($request->input('limit', 10), 50);

            $topErrors = $this->errorTrackingService->getTopErrors($limit, $filters);
            
            return response()->json([
                'success' => true,
                'data' => $topErrors->map(function ($error) {
                    return [
                        'id' => $error->id,
                        'error_group' => $error->error_group,
                        'exception_class' => $error->exception_class,
                        'message' => $error->short_message,
                        'module' => $error->module,
                        'occurrences' => $error->occurrences,
                        'severity' => $error->severity,
                        'last_seen_at' => $error->last_seen_at->toIso8601String(),
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            \Log::error('error_tracking.top.failed', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить топ ошибок',
            ], 500);
        }
    }

    /**
     * Изменить статус ошибки
     * 
     * @group Error Tracking
     * @authenticated
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:unresolved,resolved,ignored',
            ]);

            $error = ApplicationError::findOrFail($id);
            $error->update(['status' => $validated['status']]);
            
            return response()->json([
                'success' => true,
                'message' => 'Статус ошибки обновлен',
                'data' => [
                    'id' => $error->id,
                    'status' => $error->status,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Ошибка не найдена',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Неверные данные',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('error_tracking.update_status.failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось обновить статус',
            ], 500);
        }
    }

    /**
     * График ошибок во времени (для Grafana)
     * 
     * @group Error Tracking
     * @authenticated
     */
    public function timeseries(Request $request): JsonResponse
    {
        try {
            $from = $request->input('from', now()->subDays(7)->toIso8601String());
            $to = $request->input('to', now()->toIso8601String());
            
            $interval = $request->input('interval', '1 hour'); // '1 hour', '1 day', etc
            
            $query = ApplicationError::query()
                ->select(
                    DB::raw("DATE_TRUNC('{$interval}', last_seen_at) as time"),
                    DB::raw('COUNT(*) as errors_count'),
                    DB::raw('SUM(occurrences) as total_occurrences')
                )
                ->whereBetween('last_seen_at', [$from, $to])
                ->groupBy('time')
                ->orderBy('time');

            if ($request->has('organization_id')) {
                $query->where('organization_id', $request->input('organization_id'));
            }

            if ($request->has('severity')) {
                $query->where('severity', $request->input('severity'));
            }

            $data = $query->get();
            
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            \Log::error('error_tracking.timeseries.failed', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось загрузить данные графика',
            ], 500);
        }
    }
}

