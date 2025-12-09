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
        if ($siteRequest->request_type->value !== 'material') {
            throw new \DomainException('Заявка на закупку может быть создана только из заявки на материалы');
        }

        DB::beginTransaction();
        try {
            // Генерируем номер заявки
            $requestNumber = $this->generateRequestNumber($siteRequest->organization_id);

            $purchaseRequest = PurchaseRequest::create([
                'organization_id' => $siteRequest->organization_id,
                'site_request_id' => $siteRequest->id,
                'assigned_to' => $assignedTo,
                'request_number' => $requestNumber,
                'status' => PurchaseRequestStatusEnum::PENDING,
                'notes' => "Создана из заявки с объекта: {$siteRequest->title}",
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
     */
    private function generateRequestNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastRequest = PurchaseRequest::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastRequest && preg_match('/(\d+)$/', $lastRequest->request_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }

        return sprintf('ЗЗ-%s%s-%04d', $year, $month, $nextNumber);
    }

    /**
     * Инвалидация кеша
     */
    private function invalidateCache(int $organizationId): void
    {
        Cache::forget("procurement_purchase_requests_{$organizationId}");
    }
}

