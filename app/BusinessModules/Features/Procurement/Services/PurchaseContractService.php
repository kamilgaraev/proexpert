<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Models\Contract;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseContractService
{
    public function createManualContract(array $data, int $organizationId): Contract
    {
        $this->validateProcurementContractCreation($data, $organizationId);

        DB::beginTransaction();

        try {
            $contract = Contract::create([
                'organization_id' => $organizationId,
                'project_id' => $data['project_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'contract_category' => 'procurement',
                'number' => $data['number'] ?? $this->generateContractNumber($organizationId),
                'date' => $data['date'],
                'subject' => $data['subject'],
                'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
                'base_amount' => $data['total_amount'],
                'total_amount' => $data['total_amount'],
                'status' => ContractStatusEnum::DRAFT,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_fixed_amount' => true,
            ]);

            try {
                app(\App\Services\Contract\ContractStateEventService::class)
                    ->createContractCreatedEvent($contract);
            } catch (\Exception $e) {
                Log::warning('Failed to create contract state event for manual procurement contract', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return $contract->fresh(['supplier', 'project', 'organization']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createFromOrder(PurchaseOrder $order): Contract
    {
        $validationData = [
            'supplier_id' => $order->supplier_id,
        ];

        $this->validateProcurementContractCreation($validationData, $order->organization_id);

        DB::beginTransaction();

        try {
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
                'notes' => "Создан из заказа поставщику: {$order->order_number}",
                'is_fixed_amount' => true,
            ]);

            $order->update(['contract_id' => $contract->id]);

            try {
                app(\App\Services\Contract\ContractStateEventService::class)
                    ->createContractCreatedEvent($contract);
            } catch (\Exception $e) {
                Log::warning('Failed to create contract state event for procurement', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
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

    public function validateProcurementContractCreation(array $data, int $organizationId): void
    {
        $accessController = app(AccessController::class);

        if (!$accessController->hasModuleAccess($organizationId, 'procurement')) {
            throw new \DomainException(
                'Модуль "Управление закупками" не активирован. Активируйте модуль для создания договоров поставки.'
            );
        }

        if (!$accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
            throw new \DomainException(
                'Модуль "Базовое управление складом" не активирован. Он необходим для работы с договорами поставки.'
            );
        }

        if (empty($data['supplier_id']) && empty($data['contractor_id'])) {
            throw new \InvalidArgumentException(
                'Необходимо указать поставщика (supplier_id) для создания договора поставки'
            );
        }

        if (!empty($data['supplier_id'])) {
            $supplier = \App\Models\Supplier::find($data['supplier_id']);

            if (!$supplier || $supplier->organization_id !== $organizationId) {
                throw new \InvalidArgumentException('Поставщик не найден или не принадлежит организации');
            }
        }
    }

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
            $nextNumber = (int) $matches[1] + 1;
        }

        return sprintf('ДП-%s%s-%04d', $year, $month, $nextNumber);
    }
}
