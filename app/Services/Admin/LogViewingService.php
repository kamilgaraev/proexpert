<?php

namespace App\Services\Admin;

use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Repositories\Interfaces\Log\WorkCompletionLogRepositoryInterface;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LogViewingService
{
    protected MaterialUsageLogRepositoryInterface $materialLogRepo;
    protected WorkCompletionLogRepositoryInterface $workLogRepo;

    public function __construct(
        MaterialUsageLogRepositoryInterface $materialLogRepo,
        WorkCompletionLogRepositoryInterface $workLogRepo
    ) {
        $this->materialLogRepo = $materialLogRepo;
        $this->workLogRepo = $workLogRepo;
    }

    /**
     * Helper для получения ID текущей организации администратора.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId) {
            Log::error('Failed to determine organization context in LogViewingService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен для просмотра логов.', 500);
        }
        return (int)$organizationId;
    }

    /**
     * Подготовка фильтров и параметров пагинации для логов.
     */
    protected function prepareLogRequestParams(Request $request, array $allowedFilters): array
    {
        $params = [];
        $params['filters'] = [];
        foreach ($allowedFilters as $key) {
            if ($request->has($key) && !is_null($request->query($key)) && $request->query($key) !== '') {
                $params['filters'][$key] = $request->query($key);
            }
        }

        // Обработка дат
        if (!empty($params['filters']['date_from'])) {
            try {
                $params['filters']['date_from'] = Carbon::parse($params['filters']['date_from'])->startOfDay();
            } catch (\Exception $e) {
                unset($params['filters']['date_from']);
            }
        }
        if (!empty($params['filters']['date_to'])) {
            try {
                $params['filters']['date_to'] = Carbon::parse($params['filters']['date_to'])->endOfDay();
            } catch (\Exception $e) {
                unset($params['filters']['date_to']);
            }
        }

        // Параметры пагинации и сортировки
        $params['perPage'] = (int)$request->query('per_page', 15);
        $params['sortBy'] = $request->query('sort_by', 'created_at'); // По умолчанию по дате создания
        $params['sortDirection'] = $request->query('sort_direction', 'desc');

        // TODO: Валидация sortBy и sortDirection

        return $params;
    }

    /**
     * Получить пагинированный список логов использования материалов.
     */
    public function getMaterialUsageLogs(Request $request): LengthAwarePaginator
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);
            $params = $this->prepareLogRequestParams($request, ['project_id', 'material_id', 'user_id', 'date_from', 'date_to', 'operation_type']);

            return $this->materialLogRepo->getPaginatedLogs(
                $organizationId,
                $params['perPage'],
                $params['filters'],
                $params['sortBy'],
                $params['sortDirection']
            );
        } catch (BusinessLogicException $e) {
            // Перебрасываем BusinessLogicException, чтобы его обработал стандартный обработчик Laravel
            throw $e;
        } catch (\Throwable $e) {
            // Логируем любую другую неожиданную ошибку
            Log::error('[LogViewingService@getMaterialUsageLogs] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                // 'trace' => $e->getTraceAsString(), // Можно добавить для более детальной отладки, но может быть очень большим
            ]);
            // Возвращаем BusinessLogicException, чтобы фронтенд получил JSON-ошибку
            throw new BusinessLogicException('Внутренняя ошибка сервера при получении логов материалов.', 500, $e);
        }
    }

    /**
     * Получить пагинированный список логов выполнения работ.
     */
    public function getWorkCompletionLogs(Request $request): LengthAwarePaginator
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);
            $params = $this->prepareLogRequestParams($request, ['project_id', 'work_type_id', 'user_id', 'date_from', 'date_to']);

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
            throw new BusinessLogicException('Внутренняя ошибка сервера при получении логов работ.', 500, $e);
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
            $page = (int) $request->query('page', 1);
            $perPage = (int) $request->query('per_page', 15);

            $allowedCategories = ['all', 'audit', 'business', 'security'];
            if (!in_array($category, $allowedCategories)) {
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
            $lastPage = (int) ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            
            $paginatedLogs = array_slice($logs, $offset, $perPage);

            return [
                'data' => $paginatedLogs,
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
            throw new BusinessLogicException('Внутренняя ошибка сервера при получении системных логов.', 500, $e);
        }
    }

    protected function readStructuredLogs(int $organizationId, string $category, array $filters): array
    {
        $logs = [];
        $targetCategories = $category === 'all' ? ['AUDIT', 'BUSINESS', 'SECURITY'] : [strtoupper($category)];
        
        $logFiles = glob(storage_path('logs/*.log'));
        if ($logFiles === false || empty($logFiles)) {
            return [];
        }

        rsort($logFiles);
        $filesToRead = array_slice($logFiles, 0, 3);
        $totalLines = 0;
        $matchedLines = 0;

        foreach ($filesToRead as $file) {
            if (!file_exists($file) || !is_readable($file)) {
                continue;
            }

            $handle = fopen($file, 'r');
            if (!$handle) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $totalLines++;
                
                if (strpos($line, '{') === false) {
                    continue;
                }

                $jsonStart = strpos($line, '{');
                $jsonPart = substr($line, $jsonStart);
                
                $logEntry = @json_decode($jsonPart, true);
                if (!$logEntry || !is_array($logEntry)) {
                    continue;
                }
                
                if (!isset($logEntry['category']) || !in_array($logEntry['category'], $targetCategories)) {
                    continue;
                }

                $logOrgId = null;
                if (isset($logEntry['organization_id']) && $logEntry['organization_id'] !== null) {
                    $logOrgId = (int) $logEntry['organization_id'];
                } elseif (isset($logEntry['context']['organization_id']) && $logEntry['context']['organization_id'] !== null) {
                    $logOrgId = (int) $logEntry['context']['organization_id'];
                }

                if ($logOrgId === null || $logOrgId !== $organizationId) {
                    continue;
                }

                if (!empty($filters['level']) && strtoupper($logEntry['level'] ?? '') !== strtoupper($filters['level'])) {
                    continue;
                }

                if (!empty($filters['event']) && !str_contains($logEntry['event'] ?? '', $filters['event'])) {
                    continue;
                }

                if (!empty($filters['user_id'])) {
                    $logUserId = $logEntry['user_id'] ?? $logEntry['context']['user_id'] ?? null;
                    if ($logUserId === null || (int) $logUserId !== (int) $filters['user_id']) {
                        continue;
                    }
                }

                if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
                    try {
                        $timestamp = $logEntry['timestamp'] ?? null;
                        if (!$timestamp) {
                            continue;
                        }
                        
                        $logDate = Carbon::parse($timestamp);
                        
                        if (!empty($filters['date_from'])) {
                            $dateFrom = Carbon::parse($filters['date_from'])->startOfDay();
                            if ($logDate->lt($dateFrom)) {
                                continue;
                            }
                        }
                        
                        if (!empty($filters['date_to'])) {
                            $dateTo = Carbon::parse($filters['date_to'])->endOfDay();
                            if ($logDate->gt($dateTo)) {
                                continue;
                            }
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                $matchedLines++;
                $logs[] = $logEntry;
                
                if ($matchedLines >= 1000) {
                    break 2;
                }
            }

            fclose($handle);
        }

        usort($logs, function ($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });

        return $logs;
    }
} 