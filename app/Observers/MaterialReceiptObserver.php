<?php

namespace App\Observers;

use App\Models\MaterialReceipt;
use App\Models\MaterialBalance;
use App\Models\Models\Log\MaterialUsageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class MaterialReceiptObserver
{
    public function created(MaterialReceipt $receipt): void
    {
        $this->createUsageLogAndUpdateBalance($receipt);
    }

    public function updated(MaterialReceipt $receipt): void
    {
        if ($receipt->wasChanged(['quantity', 'unit_price'])) {
            $this->updateUsageLogAndBalance($receipt);
        }
    }

    public function deleted(MaterialReceipt $receipt): void
    {
        $this->deleteUsageLogAndUpdateBalance($receipt);
    }

    protected function createUsageLogAndUpdateBalance(MaterialReceipt $receipt): void
    {
        try {
            DB::transaction(function () use ($receipt) {
                // Создаем запись в логе операций
                MaterialUsageLog::create([
                    'organization_id' => $receipt->organization_id,
                    'project_id' => $receipt->project_id,
                    'material_id' => $receipt->material_id,
                    'user_id' => $receipt->user_id,
                    'operation_type' => 'receipt',
                    'quantity' => $receipt->quantity,
                    'unit_price' => $receipt->unit_price,
                    'total_price' => $receipt->total_amount,
                    'supplier_id' => $receipt->supplier_id,
                    'document_number' => $receipt->document_number,
                    'invoice_date' => $receipt->receipt_date,
                    'usage_date' => $receipt->receipt_date,
                    'notes' => 'Автоматически создано при поступлении материала',
                    'receipt_document_reference' => "receipt_{$receipt->id}",
                ]);

                // Обновляем баланс материала
                $this->updateMaterialBalance($receipt);
            });
        } catch (\Exception $e) {
            Log::error('Failed to process material receipt creation', [
                'receipt_id' => $receipt->id,
                'material_id' => $receipt->material_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function updateUsageLogAndBalance(MaterialReceipt $receipt): void
    {
        try {
            DB::transaction(function () use ($receipt) {
                // Находим связанную запись в логе и обновляем ее
                $log = MaterialUsageLog::where('receipt_document_reference', "receipt_{$receipt->id}")
                    ->where('operation_type', 'receipt')
                    ->first();

                if ($log) {
                    $log->update([
                        'quantity' => $receipt->quantity,
                        'unit_price' => $receipt->unit_price,
                        'total_price' => $receipt->total_amount,
                        'invoice_date' => $receipt->receipt_date,
                        'usage_date' => $receipt->receipt_date,
                    ]);
                }

                $this->updateMaterialBalance($receipt);
            });
        } catch (\Exception $e) {
            Log::error('Failed to process material receipt update', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function deleteUsageLogAndUpdateBalance(MaterialReceipt $receipt): void
    {
        try {
            DB::transaction(function () use ($receipt) {
                // Удаляем связанную запись из лога
                MaterialUsageLog::where('receipt_document_reference', "receipt_{$receipt->id}")
                    ->where('operation_type', 'receipt')
                    ->delete();

                $this->updateMaterialBalance($receipt);
            });
        } catch (\Exception $e) {
            Log::error('Failed to process material receipt deletion', [
                'receipt_id' => $receipt->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function updateMaterialBalance(MaterialReceipt $receipt): void
    {
        $balance = MaterialBalance::firstOrCreate([
            'organization_id' => $receipt->organization_id,
            'project_id' => $receipt->project_id,
            'material_id' => $receipt->material_id,
        ], [
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'average_price' => 0,
            'last_update_date' => now()->toDateString(),
        ]);

        $currentQuantity = $this->calculateCurrentBalance(
            $receipt->organization_id,
            $receipt->project_id,
            $receipt->material_id
        );

        $balance->update([
            'available_quantity' => $currentQuantity,
            'last_update_date' => now()->toDateString(),
        ]);
    }

    protected function calculateCurrentBalance(int $organizationId, int $projectId, int $materialId): float
    {
        $receipts = MaterialUsageLog::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('material_id', $materialId)
            ->where('operation_type', 'receipt')
            ->sum('quantity');

        $writeOffs = MaterialUsageLog::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('material_id', $materialId)
            ->where('operation_type', 'write_off')
            ->sum('quantity');

        return (float)($receipts - $writeOffs);
    }
} 