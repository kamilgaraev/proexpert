<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\ApplicationError;
use App\Services\ErrorTracking\ErrorTrackingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ErrorTrackingController extends Controller
{
    public function __construct(private readonly ErrorTrackingService $errorTrackingService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:unresolved,resolved,ignored'],
            'severity' => ['nullable', 'in:warning,error,critical'],
            'module' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $errors = $this->errorTrackingService->getRecent(
                (int) ($validated['limit'] ?? 50),
                $this->filters($validated, ['organization_id', 'status', 'severity', 'module']),
            );

            return AdminResponse::success($errors->map(fn (ApplicationError $error): array => $this->summary($error))->values());
        } catch (Throwable $exception) {
            return $this->serverError('error_tracking.index.failed', $exception);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $error = ApplicationError::query()->findOrFail($id);

            return AdminResponse::success($this->summary($error));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('errors.resource_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->serverError('error_tracking.show.failed', $exception);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'module' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:unresolved,resolved,ignored'],
            'severity' => ['nullable', 'in:warning,error,critical'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        try {
            return AdminResponse::success($this->errorTrackingService->getStatistics(
                $this->filters($validated, ['organization_id', 'module', 'status', 'severity', 'days']),
            ));
        } catch (Throwable $exception) {
            return $this->serverError('error_tracking.statistics.failed', $exception);
        }
    }

    public function top(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $errors = $this->errorTrackingService->getTopErrors(
                (int) ($validated['limit'] ?? 10),
                $this->filters($validated, ['organization_id', 'days']),
            );

            return AdminResponse::success($errors->map(fn (ApplicationError $error): array => $this->summary($error))->values());
        } catch (Throwable $exception) {
            return $this->serverError('error_tracking.top.failed', $exception);
        }
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:unresolved,resolved,ignored'],
        ]);

        try {
            $error = ApplicationError::query()->findOrFail($id);
            $error->update(['status' => $validated['status']]);

            return AdminResponse::success([
                'id' => $error->id,
                'status' => $error->status,
            ]);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('errors.resource_not_found'), 404);
        } catch (Throwable $exception) {
            return $this->serverError('error_tracking.update_status.failed', $exception);
        }
    }

    public function timeseries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'interval' => ['nullable', 'in:hour,day'],
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'severity' => ['nullable', 'in:warning,error,critical'],
        ]);

        $intervals = ['hour' => 'hour', 'day' => 'day'];
        $interval = $intervals[$validated['interval'] ?? 'hour'];
        $from = $validated['from'] ?? now()->subDays(7);
        $to = $validated['to'] ?? now();

        try {
            $query = ApplicationError::query()
                ->select(
                    DB::raw("DATE_TRUNC('{$interval}', last_seen_at) as time"),
                    DB::raw('COUNT(*) as errors_count'),
                    DB::raw('SUM(occurrences) as total_occurrences'),
                )
                ->whereBetween('last_seen_at', [$from, $to])
                ->groupBy('time')
                ->orderBy('time');

            if (isset($validated['organization_id'])) {
                $query->where('organization_id', $validated['organization_id']);
            }

            if (isset($validated['severity'])) {
                $query->where('severity', $validated['severity']);
            }

            return AdminResponse::success($query->get());
        } catch (Throwable $exception) {
            return $this->serverError('error_tracking.timeseries.failed', $exception);
        }
    }

    private function filters(array $validated, array $allowed): array
    {
        $filters = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $validated) && $validated[$key] !== null && $validated[$key] !== '') {
                $filters[$key] = $validated[$key];
            }
        }

        return $filters;
    }

    private function summary(ApplicationError $error): array
    {
        return [
            'id' => $error->id,
            'error_hash' => $error->error_hash,
            'error_group' => $error->error_group,
            'exception_class' => $error->exception_class,
            'module' => $error->module,
            'occurrences' => $error->occurrences,
            'severity' => $error->severity,
            'status' => $error->status,
            'first_seen_at' => $error->first_seen_at?->toIso8601String(),
            'last_seen_at' => $error->last_seen_at?->toIso8601String(),
        ];
    }

    private function serverError(string $event, Throwable $exception): JsonResponse
    {
        Log::error($event, ['exception_class' => $exception::class]);

        return AdminResponse::error(trans_message('errors.server_error'), 500);
    }
}
