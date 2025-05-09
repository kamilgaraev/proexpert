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
} 