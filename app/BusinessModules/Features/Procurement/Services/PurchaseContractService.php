<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Models\Contract;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use Illuminate\Support\Facades\DB;
use App\Modules\Core\AccessController;

/**
 * Сервис для работы с договорами поставки
 */
class PurchaseContractService
{
    /**
     * Создать договор поставки из заказа
     */
    public function createFromOrder(PurchaseOrder $order): Contract
    {
        // Подготавливаем данные для валидации
        $validationData = [
            'supplier_id' => $order->supplier_id,
        ];

        // Проверяем активацию модулей и данные
        $this->validateProcurementContractCreation($validationData, $order->organization_id);

        DB::beginTransaction();
        try {
            // Генерируем номер договора
            $contractNumber = $this->generateContractNumber($order->organization_id);

            $contract = Contract::create([
                'organization_id' => $order->organization_id,
                'project_id' => $order->purchaseRequest?->siteRequest?->project_id,
                'supplier_id' => $order->supplier_id,
                'contract_category' => 'procurement',
                'number' => $contractNumber,
                'date' => now(),
                'subject' => "Договор поставки по заказу {$order->order_number}",
                'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
                'base_amount' => $order->total_amount,
                'total_amount' => $order->total_amount,
                'status' => ContractStatusEnum::DRAFT,
                'currency' => $order->currency,
                'notes' => "Создан из заказа поставщику: {$order->order_number}",
                'uses_event_sourcing' => true,
            ]);

            $order->update(['contract_id' => $contract->id]);

            try {
                $stateEventService = app(\App\Services\Contract\ContractStateEventService::class);
                $stateEventService->createContractCreatedEvent($contract);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to create contract state event for procurement', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseContractCreated($contract, $order));

            return $contract->fresh(['supplier', 'organization']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Валидация создания договора поставки
     */
    public function validateProcurementContractCreation(array $data, int $organizationId): void
    {
        $accessController = app(AccessController::class);

        // Проверяем активацию модуля procurement
        if (!$accessController->hasModuleAccess($organizationId, 'procurement')) {
            throw new \DomainException(
                'Модуль "Управление закупками" не активирован. Активируйте модуль для создания договоров поставки.'
            );
        }

        // Проверяем активацию модуля basic-warehouse
        if (!$accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
            throw new \DomainException(
                'Модуль "Базовое управление складом" не активирован. Он необходим для работы с договорами поставки.'
            );
        }

        // Проверяем, что либо contractor_id, либо supplier_id заполнен
        if (empty($data['supplier_id']) && empty($data['contractor_id'])) {
            // Для обратной совместимости или если данные не переданы (хотя в createFromOrder мы их передаем)
            // Но ошибка "Необходимо указать..." возникает именно здесь если массив пустой
            throw new \InvalidArgumentException(
                'Необходимо указать поставщика (supplier_id) для создания договора поставки'
            );
        }

        // Если указан supplier_id, проверяем существование поставщика
        if (!empty($data['supplier_id'])) {
            $supplier = \App\Models\Supplier::find($data['supplier_id']);
            if (!$supplier || $supplier->organization_id !== $organizationId) {
                throw new \InvalidArgumentException('Поставщик не найден или не принадлежит организации');
            }
        }
    }

    /**
     * Связать договор с заказом
     */
    public function linkToPurchaseOrder(Contract $contract, PurchaseOrder $order): void
    {
        if ($order->contract_id && $order->contract_id !== $contract->id) {
            throw new \DomainException('Заказ уже связан с другим договором');
        }

        DB::beginTransaction();
        try {
            $order->update(['contract_id' => $contract->id]);
            $contract->update(['supplier_id' => $order->supplier_id]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Генерировать номер договора поставки
     */
    private function generateContractNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastContract = Contract::where('organization_id', $organizationId)
            ->where('contract_category', 'procurement')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastContract && preg_match('/(\d+)$/', $lastContract->number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }

        return sprintf('ДП-%s%s-%04d', $year, $month, $nextNumber);
    }
}

