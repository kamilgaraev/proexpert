<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Сервис для работы с заявками на закупку
 */
class PurchaseRequestService
{
    private const CACHE_TTL = 3600;

    /**
     * Получить заявку по ID
     */
    public function find(int $id, int $organizationId): ?PurchaseRequest
    {
        return PurchaseRequest::forOrganization($organizationId)
            ->with(['siteRequest.project', 'assignedUser', 'purchaseOrders.supplier'])
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
        $query = PurchaseRequest::forOrganization($organizationId)
            ->with(['siteRequest.project', 'assignedUser', 'purchaseOrders']);

        // Применяем фильтры
        if (isset($filters['status'])) {
            $query->withStatus($filters['status']);
        }

        if (isset($filters['site_request_id'])) {
            $query->where('site_request_id', $filters['site_request_id']);
        }

        if (isset($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        // Сортировка
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage);
    }

    /**
     * Создать заявку на закупку из заявки с объекта
     */
    public function createFromSiteRequest(SiteRequest $siteRequest, ?int $assignedTo = null): PurchaseRequest
    {
        $allowedTypes = ['material_request', 'equipment_request', 'personnel_request'];
        if (!in_array($siteRequest->request_type->value, $allowedTypes)) {
            throw new \DomainException('Заявка на закупку может быть создана только из заявки на материалы, технику или персонал');
        }

        DB::beginTransaction();
        try {
            // Генерируем номер заявки (внутри транзакции для атомарности)
            $requestNumber = $this->generateRequestNumber($siteRequest->organization_id);

            // Формируем описание типа заявки
            $requestTypeLabel = match($siteRequest->request_type->value) {
                'material_request' => 'заявки на материалы',
                'equipment_request' => 'заявки на технику',
                'personnel_request' => 'заявки на персонал',
                default => 'заявки с объекта',
            };

            $purchaseRequest = PurchaseRequest::create([
                'organization_id' => $siteRequest->organization_id,
                'site_request_id' => $siteRequest->id,
                'assigned_to' => $assignedTo,
                'request_number' => $requestNumber,
                'status' => PurchaseRequestStatusEnum::PENDING,
                'notes' => "Создана из {$requestTypeLabel}: {$siteRequest->title}",
            ]);

            DB::commit();

            // Инвалидация кеша
            $this->invalidateCache($siteRequest->organization_id);

            // Отправляем событие
            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated($purchaseRequest));

            \Log::info('procurement.purchase_request.created', [
                'purchase_request_id' => $purchaseRequest->id,
                'site_request_id' => $siteRequest->id,
                'organization_id' => $siteRequest->organization_id,
            ]);

            return $purchaseRequest->fresh(['siteRequest', 'assignedUser']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Создать заявку на закупку вручную
     */
    public function create(int $organizationId, array $data): PurchaseRequest
    {
        // Проверяем лимиты организации
        $this->checkLimits($organizationId);

        DB::beginTransaction();
        try {
            $requestNumber = $this->generateRequestNumber($organizationId);

            $purchaseRequest = PurchaseRequest::create([
                'organization_id' => $organizationId,
                'site_request_id' => $data['site_request_id'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
                'request_number' => $requestNumber,
                'status' => PurchaseRequestStatusEnum::DRAFT,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            DB::commit();

            $this->invalidateCache($organizationId);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestCreated($purchaseRequest));

            return $purchaseRequest->fresh(['siteRequest', 'assignedUser']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Проверить лимиты организации
     */
    private function checkLimits(int $organizationId): void
    {
        $module = app(\App\BusinessModules\Features\Procurement\ProcurementModule::class);
        $limits = $module->getLimits();

        // Проверяем лимит заявок на закупку в месяц
        if ($limits['max_purchase_requests_per_month']) {
            $count = PurchaseRequest::forOrganization($organizationId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            if ($count >= $limits['max_purchase_requests_per_month']) {
                throw new \DomainException('Достигнут лимит заявок на закупку в текущем месяце');
            }
        }

        // Проверяем лимит заказов поставщикам в месяц
        if ($limits['max_purchase_orders_per_month']) {
            $ordersCount = \App\BusinessModules\Features\Procurement\Models\PurchaseOrder::forOrganization($organizationId)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count();

            if ($ordersCount >= $limits['max_purchase_orders_per_month']) {
                throw new \DomainException('Достигнут лимит заказов поставщикам в текущем месяце');
            }
        }
    }

    /**
     * Одобрить заявку
     */
    public function approve(PurchaseRequest $request, int $userId): PurchaseRequest
    {
        if (!$request->canBeApproved()) {
            throw new \DomainException('Заявка не может быть одобрена в текущем статусе');
        }

        DB::beginTransaction();
        try {
            $request->update([
                'status' => PurchaseRequestStatusEnum::APPROVED,
            ]);

            DB::commit();

            $this->invalidateCache($request->organization_id);

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseRequestApproved($request, $userId));

            return $request->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Отклонить заявку
     */
    public function reject(PurchaseRequest $request, int $userId, string $reason): PurchaseRequest
    {
        if (!$request->canBeRejected()) {
            throw new \DomainException('Заявка не может быть отклонена в текущем статусе');
        }

        DB::beginTransaction();
        try {
            $request->update([
                'status' => PurchaseRequestStatusEnum::REJECTED,
                'notes' => ($request->notes ? $request->notes . "\n\n" : '') . "Отклонена: {$reason}",
            ]);

            DB::commit();

            $this->invalidateCache($request->organization_id);

            return $request->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Создать заказ поставщику из заявки
     */
    public function assignToSupplier(PurchaseRequest $request, int $supplierId): PurchaseOrder
    {
        if ($request->status !== PurchaseRequestStatusEnum::APPROVED) {
            throw new \DomainException('Заявка должна быть одобрена перед созданием заказа');
        }

        $orderService = app(PurchaseOrderService::class);
        return $orderService->create($request, $supplierId, []);
    }

    /**
     * Генерировать номер заявки
     * Использует атомарный инкремент через таблицу счетчиков для предотвращения race condition
     * Работает корректно даже с Laravel Octane и connection pooling
     */
    private function generateRequestNumber(int $organizationId): string
    {
        $year = (int) date('Y');
        $month = (int) date('m');
        
        // Используем raw SQL для атомарного increment с INSERT ON CONFLICT
        // Метод вызывается внутри транзакции, поэтому не создаем новую
        $result = DB::selectOne("
            INSERT INTO purchase_request_number_counters (organization_id, year, month, last_number, created_at, updated_at)
            VALUES (?, ?, ?, 1, NOW(), NOW())
            ON CONFLICT (organization_id, year, month) 
            DO UPDATE SET 
                last_number = purchase_request_number_counters.last_number + 1,
                updated_at = NOW()
            RETURNING last_number
        ", [$organizationId, $year, $month]);
        
        $newNumber = $result->last_number;
        $requestNumber = sprintf('ЗЗ-%d%02d-%04d', $year, $month, $newNumber);
        
        \Log::debug('procurement.purchase_request.number_generated', [
            'organization_id' => $organizationId,
            'year' => $year,
            'month' => $month,
            'generated_number' => $requestNumber,
            'counter_value' => $newNumber,
        ]);
        
        return $requestNumber;
    }

    /**
     * Инвалидация кеша
     */
    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_requests_{$organizationId}");
    }
}

