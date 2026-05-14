<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\BusinessLogicException;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class LogViewingService
{
    private const DEFAULT_PER_PAGE = 15;
    private const MAX_PER_PAGE = 100;

    private const ALLOWED_WORK_LOG_SORTS = [
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'completion_date' => 'completion_date',
        'project_id' => 'project_id',
        'work_type_id' => 'work_type_id',
        'user_id' => 'user_id',
        'quantity' => 'quantity',
        'unit_price' => 'unit_price',
        'total_price' => 'total_price',
    ];

    public function __construct(
        protected WorkCompletionLogRepositoryInterface $workLogRepo
    ) {
    }

    public function getMaterialUsageLogs(Request $request): LengthAwarePaginator
    {
        throw new BusinessLogicException(trans_message('logs.material_usage_deprecated'), 410);
    }

    public function getWorkCompletionLogs(Request $request): LengthAwarePaginator
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);
            $params = $this->prepareLogRequestParams($request, [
                'project_id',
                'work_type_id',
                'user_id',
                'date_from',
                'date_to',
            ]);

            return $this->workLogRepo->getPaginatedLogs(
                $organizationId,
                $params['perPage'],
                $params['filters'],
                $params['sortBy'],
                $params['sortDirection']
            );
        } catch (BusinessLogicException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[LogViewingService@getWorkCompletionLogs] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw new BusinessLogicException(trans_message('logs.work_load_error'), 500, $e);
        }
    }

    public function getSystemLogs(Request $request): array
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);

            $category = $request->query('category', 'all');
            $level = $request->query('level');
            $event = $request->query('event');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');
            $userId = $request->query('user_id');
            $page = $this->normalizePage($request->query('page'));
            $perPage = $this->normalizePerPage($request->query('per_page'));

            if (!is_string($category) || !in_array($category, ['all', 'audit', 'business', 'security'], true)) {
                $category = 'all';
            }

            $logs = $this->readStructuredLogs($organizationId, $category, [
                'level' => $level,
                'event' => $event,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'user_id' => $userId,
            ]);

            $total = count($logs);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;

            return [
                'data' => array_slice($logs, $offset, $perPage),
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ];
        } catch (BusinessLogicException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[LogViewingService@getSystemLogs] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw new BusinessLogicException(trans_message('logs.system_load_error'), 500, $e);
        }
    }

    protected function getCurrentOrgId(Request $request): int
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            Log::error('Failed to determine organization context in LogViewingService', [
                'user_id' => $user?->id,
                'request_attributes' => $request->attributes->all(),
            ]);

            throw new BusinessLogicException(trans_message('logs.organization_context_missing'), 500);
        }

        return (int) $organizationId;
    }

    protected function prepareLogRequestParams(Request $request, array $allowedFilters): array
    {
        $params = [
            'filters' => [],
        ];

        foreach ($allowedFilters as $key) {
            if ($request->has($key) && $request->query($key) !== null && $request->query($key) !== '') {
                $params['filters'][$key] = $request->query($key);
            }
        }

        if (!empty($params['filters']['date_from'])) {
            try {
                $params['filters']['date_from'] = Carbon::parse($params['filters']['date_from'])->startOfDay();
            } catch (\Exception) {
                unset($params['filters']['date_from']);
            }
        }

        if (!empty($params['filters']['date_to'])) {
            try {
                $params['filters']['date_to'] = Carbon::parse($params['filters']['date_to'])->endOfDay();
            } catch (\Exception) {
                unset($params['filters']['date_to']);
            }
        }

        $sortBy = $request->query('sort_by', 'created_at');
        $sortDirection = $request->query('sort_direction', 'desc');
        $sortBy = is_string($sortBy) ? strtolower($sortBy) : 'created_at';
        $sortDirection = is_string($sortDirection) ? strtolower($sortDirection) : 'desc';

        $params['perPage'] = $this->normalizePerPage($request->query('per_page'));
        $params['sortBy'] = self::ALLOWED_WORK_LOG_SORTS[$sortBy] ?? 'created_at';
        $params['sortDirection'] = in_array($sortDirection, ['asc', 'desc'], true) ? $sortDirection : 'desc';

        return $params;
    }

    protected function readStructuredLogs(int $organizationId, string $category, array $filters): array
    {
        $logs = [];
        $targetCategories = $category === 'all' ? ['AUDIT', 'BUSINESS', 'SECURITY'] : [strtoupper($category)];

        $logFiles = glob(storage_path('logs/*.log'));
        if ($logFiles === false || $logFiles === []) {
            return [];
        }

        rsort($logFiles);

        foreach (array_slice($logFiles, 0, 3) as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                if (strpos($line, '{') === false) {
                    continue;
                }

                $jsonStart = strpos($line, '{');
                if ($jsonStart === false) {
                    continue;
                }

                $logEntry = json_decode(substr($line, $jsonStart), true);
                if (!is_array($logEntry)) {
                    continue;
                }

                if (!$this->systemLogMatches($logEntry, $targetCategories, $organizationId, $filters)) {
                    continue;
                }

                $logs[] = $logEntry;

                if (count($logs) >= 1000) {
                    break 2;
                }
            }

            fclose($handle);
        }

        usort($logs, static fn (array $a, array $b): int => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));

        return $logs;
    }

    private function systemLogMatches(array $logEntry, array $targetCategories, int $organizationId, array $filters): bool
    {
        if (!isset($logEntry['category']) || !in_array($logEntry['category'], $targetCategories, true)) {
            return false;
        }

        $logOrgId = $logEntry['organization_id'] ?? $logEntry['context']['organization_id'] ?? null;
        if ($logOrgId === null || (int) $logOrgId !== $organizationId) {
            return false;
        }

        if (!empty($filters['level']) && strtoupper((string) ($logEntry['level'] ?? '')) !== strtoupper((string) $filters['level'])) {
            return false;
        }

        if (!empty($filters['event']) && !str_contains((string) ($logEntry['event'] ?? ''), (string) $filters['event'])) {
            return false;
        }

        if (!empty($filters['user_id'])) {
            $logUserId = $logEntry['user_id'] ?? $logEntry['context']['user_id'] ?? null;
            if ($logUserId === null || (int) $logUserId !== (int) $filters['user_id']) {
                return false;
            }
        }

        return $this->systemLogDateMatches($logEntry, $filters);
    }

    private function systemLogDateMatches(array $logEntry, array $filters): bool
    {
        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            return true;
        }

        try {
            $timestamp = $logEntry['timestamp'] ?? null;
            if (!$timestamp) {
                return false;
            }

            $logDate = Carbon::parse($timestamp);

            if (!empty($filters['date_from']) && $logDate->lt(Carbon::parse($filters['date_from'])->startOfDay())) {
                return false;
            }

            if (!empty($filters['date_to']) && $logDate->gt(Carbon::parse($filters['date_to'])->endOfDay())) {
                return false;
            }
        } catch (\Exception) {
            return false;
        }

        return true;
    }

    private function normalizePage(mixed $value): int
    {
        $page = filter_var($value ?? 1, FILTER_VALIDATE_INT);

        return is_int($page) && $page > 0 ? $page : 1;
    }

    private function normalizePerPage(mixed $value): int
    {
        $perPage = filter_var($value ?? self::DEFAULT_PER_PAGE, FILTER_VALIDATE_INT);

        if (!is_int($perPage) || $perPage <= 0) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }
}
