<?php

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestHistory;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestCreated;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestUpdated;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestStatusChanged;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestApproved;
use App\BusinessModules\Features\SiteRequests\Events\SiteRequestAssigned;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;

/**
 * Основной сервис для работы с заявками
 */
class SiteRequestService
{
    private const CACHE_TTL = 300; // 5 минут
    private const CACHE_TAG = 'site_requests';

    public function __construct(
        private readonly SiteRequestWorkflowService $workflowService,
        private readonly SiteRequestCalendarService $calendarService,
        private readonly SiteRequestsModule $module
    ) {}

    /**
     * Получить заявку по ID
     */
    public function find(int $id, int $organizationId): ?SiteRequest
    {
        return SiteRequest::forOrganization($organizationId)
            ->with(['project', 'user', 'assignedUser', 'files', 'calendarEvent'])
            ->find($id);
    }

    /**
     * Получить список заявок с пагинацией
     */
    public function paginate(
        int $organizationId,
        int $perPage = 15,
        array $filters = []
    ): LengthAwarePaginator {
        $query = SiteRequest::forOrganization($organizationId)
            ->with(['project', 'user', 'assignedUser']);

        // Применяем фильтры
        $this->applyFilters($query, $filters);

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage);
    }

    /**
     * Создать заявку
     */
    public function create(int $organizationId, int $userId, array $data): SiteRequest
    {
        // Проверяем лимиты
        $this->checkLimits($organizationId);

        $request = DB::transaction(function () use ($organizationId, $userId, $data) {
            // Создаем заявку
            $request = SiteRequest::create([
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'status' => SiteRequestStatusEnum::DRAFT->value,
                ...$data,
            ]);

            // Записываем в историю
            SiteRequestHistory::logCreated($request, $userId);

            return $request;
        });

        // Инвалидируем кеш
        $this->invalidateCache($organizationId);

        // Отправляем событие
        event(new SiteRequestCreated($request));

        // Резервируем материалы на складе если это заявка на материалы
        if ($request->request_type === SiteRequestTypeEnum::MATERIAL_REQUEST && $request->material_id) {
            $this->tryReserveMaterial($request, $organizationId);
        }

        \Log::info('site_request.created', [
            'request_id' => $request->id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'type' => $request->request_type->value,
        ]);

        return $request->fresh(['project', 'user']);
    }

    /**
     * Обновить заявку
     */
    public function update(SiteRequest $request, int $userId, array $data): SiteRequest
    {
        // Проверяем возможность редактирования
        if (!$request->canBeEdited()) {
            throw new \DomainException('Заявку нельзя редактировать в текущем статусе');
        }

        $oldValues = $request->only(array_keys($data));

        DB::transaction(function () use ($request, $userId, $data, $oldValues) {
            $request->update($data);

            // Записываем в историю
            SiteRequestHistory::logUpdated($request, $userId, $oldValues, $data);
        });

        // Инвалидируем кеш
        $this->invalidateCache($request->organization_id);

        // Отправляем событие
        event(new SiteRequestUpdated($request, $oldValues));

        \Log::info('site_request.updated', [
            'request_id' => $request->id,
            'user_id' => $userId,
        ]);

        return $request->fresh(['project', 'user', 'assignedUser', 'calendarEvent']);
    }

    /**
     * Удалить заявку (soft delete)
     */
    public function delete(SiteRequest $request, int $userId): bool
    {
        $organizationId = $request->organization_id;

        $result = DB::transaction(function () use ($request, $userId) {
            // Удаляем событие календаря
            $this->calendarService->deleteCalendarEvent($request);

            // Записываем в историю
            SiteRequestHistory::create([
                'site_request_id' => $request->id,
                'user_id' => $userId,
                'action' => SiteRequestHistory::ACTION_DELETED,
                'old_value' => $request->toArray(),
            ]);

            return $request->delete();
        });

        // Инвалидируем кеш
        $this->invalidateCache($organizationId);

        \Log::info('site_request.deleted', [
            'request_id' => $request->id,
            'user_id' => $userId,
        ]);

        return $result;
    }

    /**
     * Изменить статус заявки
     */
    public function changeStatus(
        SiteRequest $request,
        int $userId,
        string $newStatus,
        ?string $notes = null
    ): SiteRequest {
        $oldStatus = $request->status->value;

        // Проверяем возможность перехода
        if (!$this->workflowService->canTransition($request, $newStatus)) {
            throw new \DomainException("Невозможен переход из статуса '{$oldStatus}' в '{$newStatus}'");
        }

        DB::transaction(function () use ($request, $userId, $oldStatus, $newStatus, $notes) {
            $request->update(['status' => $newStatus]);

            // Записываем в историю
            SiteRequestHistory::logStatusChanged($request, $userId, $oldStatus, $newStatus, $notes);
        });

        // Инвалидируем кеш
        $this->invalidateCache($request->organization_id);

        // Отправляем событие
        event(new SiteRequestStatusChanged($request, $oldStatus, $newStatus, $userId));

        // Если статус изменился на approved, отправляем специальное событие
        if ($newStatus === SiteRequestStatusEnum::APPROVED->value) {
            event(new SiteRequestApproved($request->fresh(), $userId));
        }

        // Снимаем резервирование при отмене или отклонении заявки
        if (in_array($newStatus, [
            SiteRequestStatusEnum::CANCELLED->value,
            SiteRequestStatusEnum::REJECTED->value,
        ])) {
            $this->unreserveMaterial($request->fresh());
        }

        \Log::info('site_request.status_changed', [
            'request_id' => $request->id,
            'user_id' => $userId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        return $request->fresh();
    }

    /**
     * Назначить исполнителя
     */
    public function assign(SiteRequest $request, int $userId, int $assigneeId): SiteRequest
    {
        $oldAssignee = $request->assigned_to;

        DB::transaction(function () use ($request, $userId, $oldAssignee, $assigneeId) {
            $request->update(['assigned_to' => $assigneeId]);

            // Записываем в историю
            SiteRequestHistory::logAssigned($request, $userId, $oldAssignee, $assigneeId);
        });

        // Инвалидируем кеш
        $this->invalidateCache($request->organization_id);

        // Отправляем событие
        event(new SiteRequestAssigned($request, $assigneeId, $userId));

        \Log::info('site_request.assigned', [
            'request_id' => $request->id,
            'user_id' => $userId,
            'assignee_id' => $assigneeId,
        ]);

        return $request->fresh(['assignedUser']);
    }

    /**
     * Отправить заявку (перевести из черновика в ожидание)
     */
    public function submit(SiteRequest $request, int $userId): SiteRequest
    {
        if ($request->status !== SiteRequestStatusEnum::DRAFT) {
            throw new \DomainException('Только черновики можно отправить на обработку');
        }

        return $this->changeStatus($request, $userId, SiteRequestStatusEnum::PENDING->value);
    }

    /**
     * Подтвердить выполнение (для прораба)
     */
    public function complete(SiteRequest $request, int $userId, ?string $notes = null): SiteRequest
    {
        if ($request->status !== SiteRequestStatusEnum::FULFILLED) {
            throw new \DomainException('Подтвердить можно только выполненную заявку');
        }

        return $this->changeStatus($request, $userId, SiteRequestStatusEnum::COMPLETED->value, $notes);
    }

    /**
     * Отменить заявку
     */
    public function cancel(SiteRequest $request, int $userId, ?string $notes = null): SiteRequest
    {
        if (!$request->canBeCancelled()) {
            throw new \DomainException('Заявку нельзя отменить в текущем статусе');
        }

        return $this->changeStatus($request, $userId, SiteRequestStatusEnum::CANCELLED->value, $notes);
    }

    /**
     * Получить статистику по заявкам
     */
    public function getStatistics(int $organizationId): array
    {
        $cacheKey = "site_requests_stats_{$organizationId}";

        return Cache::tags([self::CACHE_TAG, "org_{$organizationId}"])
            ->remember($cacheKey, self::CACHE_TTL, function () use ($organizationId) {
                $requests = SiteRequest::forOrganization($organizationId)->get();

                return [
                    'total' => $requests->count(),
                    'by_status' => $requests->groupBy(fn($r) => $r->status->value)->map->count(),
                    'by_type' => $requests->groupBy(fn($r) => $r->request_type->value)->map->count(),
                    'by_priority' => $requests->groupBy(fn($r) => $r->priority->value)->map->count(),
                    'overdue' => SiteRequest::forOrganization($organizationId)->overdue()->count(),
                    'pending' => $requests->where('status', SiteRequestStatusEnum::PENDING)->count(),
                    'in_progress' => $requests->where('status', SiteRequestStatusEnum::IN_PROGRESS)->count(),
                    'personnel_stats' => $this->getPersonnelStatistics($organizationId),
                ];
            });
    }

    /**
     * Получить статистику по персоналу
     */
    private function getPersonnelStatistics(int $organizationId): array
    {
        $personnelRequests = SiteRequest::forOrganization($organizationId)
            ->ofType(SiteRequestTypeEnum::PERSONNEL_REQUEST)
            ->active()
            ->get();

        $totalPersonnel = $personnelRequests->sum('personnel_count');
        $totalCost = $personnelRequests->sum(fn($r) => $r->estimated_personnel_cost ?? 0);

        return [
            'total_requests' => $personnelRequests->count(),
            'total_personnel' => $totalPersonnel,
            'estimated_total_cost' => $totalCost,
            'by_type' => $personnelRequests->groupBy(fn($r) => $r->personnel_type?->value)->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'personnel' => $group->sum('personnel_count'),
                ];
            }),
        ];
    }

    /**
     * Получить просроченные заявки
     */
    public function getOverdueRequests(int $organizationId): Collection
    {
        return SiteRequest::forOrganization($organizationId)
            ->overdue()
            ->with(['project', 'user', 'assignedUser'])
            ->orderBy('required_date')
            ->get();
    }

    /**
     * Применить фильтры к запросу
     */
    private function applyFilters($query, array $filters): void
    {
        if (!empty($filters['status'])) {
            $query->withStatus($filters['status']);
        }

        if (!empty($filters['priority'])) {
            $query->withPriority($filters['priority']);
        }

        if (!empty($filters['request_type'])) {
            $query->ofType($filters['request_type']);
        }

        if (!empty($filters['project_id'])) {
            $query->forProject($filters['project_id']);
        }

        if (!empty($filters['user_id'])) {
            $query->forUser($filters['user_id']);
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('material_name', 'ilike', "%{$search}%");
            });
        }

        if (isset($filters['overdue']) && $filters['overdue']) {
            $query->overdue();
        }
    }

    /**
     * Проверить лимиты модуля
     */
    private function checkLimits(int $organizationId): void
    {
        $settings = $this->module->getSettings($organizationId);
        $limits = $this->module->getLimits();

        // Проверяем лимит шаблонов - не применимо для заявок
        // Лимиты на заявки неограничены в подписке
    }

    /**
     * Попытаться зарезервировать материал на складе
     */
    private function tryReserveMaterial(SiteRequest $request, int $organizationId): void
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        
        if (!$accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
            \Log::info('site_request.skip_reservation', [
                'request_id' => $request->id,
                'reason' => 'Модуль склада не активирован',
            ]);
            return;
        }

        try {
            $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
            $warehouse = $warehouseService->getOrCreateCentralWarehouse($organizationId);

            $balance = $warehouseService->getAssetBalance(
                $organizationId,
                $warehouse->id,
                $request->material_id
            );

            if ($balance && $balance->available_quantity >= $request->material_quantity) {
                $balance->reserve($request->material_quantity);

                // Сохраняем информацию о резервировании в metadata
                $request->update([
                    'metadata' => array_merge($request->metadata ?? [], [
                        'material_reserved' => true,
                        'reserved_warehouse_id' => $warehouse->id,
                        'reserved_quantity' => $request->material_quantity,
                        'reserved_at' => now()->toDateTimeString(),
                    ]),
                ]);

                \Log::info('site_request.material_reserved', [
                    'request_id' => $request->id,
                    'material_id' => $request->material_id,
                    'quantity' => $request->material_quantity,
                    'warehouse_id' => $warehouse->id,
                ]);
            } else {
                \Log::warning('site_request.insufficient_stock', [
                    'request_id' => $request->id,
                    'material_id' => $request->material_id,
                    'requested_quantity' => $request->material_quantity,
                    'available_quantity' => $balance ? $balance->available_quantity : 0,
                ]);
            }
        } catch (\Exception $e) {
            // Не прерываем создание заявки если резервирование не удалось
            \Log::warning('site_request.reservation_failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Снять резервирование материала при отмене/отклонении заявки
     */
    private function unreserveMaterial(SiteRequest $request): void
    {
        $metadata = $request->metadata ?? [];

        if (!($metadata['material_reserved'] ?? false)) {
            return;
        }

        $accessController = app(\App\Modules\Core\AccessController::class);
        
        if (!$accessController->hasModuleAccess($request->organization_id, 'basic-warehouse')) {
            return;
        }

        try {
            $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
            $warehouseId = $metadata['reserved_warehouse_id'] ?? null;
            $quantity = $metadata['reserved_quantity'] ?? null;

            if (!$warehouseId || !$quantity || !$request->material_id) {
                return;
            }

            $balance = $warehouseService->getAssetBalance(
                $request->organization_id,
                $warehouseId,
                $request->material_id
            );

            if ($balance && $balance->reserved_quantity >= $quantity) {
                $balance->unreserve($quantity);

                // Обновляем metadata
                $request->update([
                    'metadata' => array_merge($metadata, [
                        'material_reserved' => false,
                        'unreserved_at' => now()->toDateTimeString(),
                    ]),
                ]);

                \Log::info('site_request.material_unreserved', [
                    'request_id' => $request->id,
                    'material_id' => $request->material_id,
                    'quantity' => $quantity,
                    'warehouse_id' => $warehouseId,
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('site_request.unreservation_failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Инвалидировать кеш
     */
    private function invalidateCache(int $organizationId): void
    {
        Cache::tags([self::CACHE_TAG, "org_{$organizationId}"])->flush();
    }
}

