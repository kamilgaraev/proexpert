<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для работы с коммерческими предложениями
 */
class SupplierProposalService
{
    /**
     * Создать КП из заказа
     */
    public function createFromOrder(PurchaseOrder $order, array $data): SupplierProposal
    {
        DB::beginTransaction();
        try {
            $proposalNumber = $this->generateProposalNumber($order->organization_id);

            $proposal = SupplierProposal::create([
                'organization_id' => $order->organization_id,
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'proposal_number' => $proposalNumber,
                'proposal_date' => $data['proposal_date'] ?? now(),
                'status' => SupplierProposalStatusEnum::SUBMITTED,
                'total_amount' => $data['total_amount'] ?? $order->total_amount,
                'currency' => $data['currency'] ?? $order->currency,
                'valid_until' => $data['valid_until'] ?? null,
                'items' => $data['items'] ?? null,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            DB::commit();

            event(new \App\BusinessModules\Features\Procurement\Events\SupplierProposalReceived($proposal));

            return $proposal->fresh(['supplier', 'purchaseOrder']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Принять КП
     */
    public function accept(SupplierProposal $proposal): SupplierProposal
    {
        if (!$proposal->canBeAccepted()) {
            throw new \DomainException('КП не может быть принято в текущем статусе');
        }

        DB::beginTransaction();
        try {
            $proposal->update([
                'status' => SupplierProposalStatusEnum::ACCEPTED,
            ]);

            // Обновляем заказ
            if ($proposal->purchaseOrder) {
                $orderService = app(PurchaseOrderService::class);
                $orderService->confirm($proposal->purchaseOrder, [
                    'total_amount' => $proposal->total_amount,
                ]);
            }

            DB::commit();

            return $proposal->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Отклонить КП
     */
    public function reject(SupplierProposal $proposal, string $reason): SupplierProposal
    {
        if ($proposal->status->isFinal()) {
            throw new \DomainException('КП уже в финальном статусе');
        }

        DB::beginTransaction();
        try {
            $proposal->update([
                'status' => SupplierProposalStatusEnum::REJECTED,
                'notes' => ($proposal->notes ? $proposal->notes . "\n\n" : '') . "Отклонено: {$reason}",
            ]);

            DB::commit();

            return $proposal->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Генерировать номер КП
     */
    private function generateProposalNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastProposal = SupplierProposal::where('organization_id', $organizationId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;
        if ($lastProposal && preg_match('/(\d+)$/', $lastProposal->proposal_number, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }

        return sprintf('КП-%s%s-%04d', $year, $month, $nextNumber);
    }
}

