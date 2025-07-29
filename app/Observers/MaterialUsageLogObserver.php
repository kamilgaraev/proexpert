<?php

namespace App\Observers;

use App\Models\Models\Log\MaterialUsageLog;
use App\Models\MaterialBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaterialUsageLogObserver
{
    public function created(MaterialUsageLog $log): void
    {
        $this->updateMaterialBalance($log);
    }

    public function updated(MaterialUsageLog $log): void
    {
        if ($log->wasChanged(['quantity', 'operation_type'])) {
            $this->updateMaterialBalance($log);
        }
    }

    public function deleted(MaterialUsageLog $log): void
    {
        $this->updateMaterialBalance($log, true);
    }

    protected function updateMaterialBalance(MaterialUsageLog $log, bool $isDeleting = false): void
    {
        try {
            DB::transaction(function () use ($log, $isDeleting) {
                $balance = MaterialBalance::firstOrCreate([
                    'organization_id' => $log->organization_id,
                    'project_id' => $log->project_id,
                    'material_id' => $log->material_id,
                ], [
                    'available_quantity' => 0,
                    'reserved_quantity' => 0,
                    'average_price' => 0,
                    'last_update_date' => now()->toDateString(),
                ]);

                $currentQuantity = $this->calculateCurrentBalance(
                    $log->organization_id, 
                    $log->project_id, 
                    $log->material_id
                );

                $balance->update([
                    'available_quantity' => $currentQuantity,
                    'last_update_date' => now()->toDateString(),
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to update material balance', [
                'log_id' => $log->id,
                'material_id' => $log->material_id,
                'project_id' => $log->project_id,
                'error' => $e->getMessage()
            ]);
        }
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