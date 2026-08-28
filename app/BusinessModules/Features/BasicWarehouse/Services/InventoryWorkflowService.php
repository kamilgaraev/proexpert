<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Exceptions\InventoryApprovalStatusException;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class InventoryWorkflowService
{
    private const ACT_NUMBER_LOCK_NAMESPACE = 4_946_000_000_000_000;

    public function __construct(
        private readonly InventoryStockService $stockService,
    ) {}

    /** @param list<int> $commissionMembers */
    public function createAct(
        int $organizationId,
        int $warehouseId,
        string $inventoryDate,
        int $createdBy,
        array $commissionMembers,
        ?string $notes,
    ): InventoryAct {
        return DB::transaction(function () use (
            $organizationId,
            $warehouseId,
            $inventoryDate,
            $createdBy,
            $commissionMembers,
            $notes,
        ): InventoryAct {
            DB::statement(
                'SELECT pg_advisory_xact_lock(CAST(? AS bigint))',
                [self::ACT_NUMBER_LOCK_NAMESPACE + $organizationId]
            );

            $actDate = new DateTimeImmutable($inventoryDate);
            $maxSequence = InventoryAct::query()
                ->where('organization_id', $organizationId)
                ->selectRaw(<<<'SQL'
                    COALESCE(
                        MAX(
                            CASE
                                WHEN act_number ~ '^INV-[0-9]{8}-[0-9]+$'
                                THEN CAST(SPLIT_PART(act_number, '-', 3) AS BIGINT)
                                ELSE 0
                            END
                        ),
                        0
                    ) AS max_sequence
                    SQL)
                ->value('max_sequence');
            $sequence = (int) $maxSequence + 1;

            $act = InventoryAct::query()->create([
                'organization_id' => $organizationId,
                'warehouse_id' => $warehouseId,
                'act_number' => 'INV-'.$actDate->format('Ymd').'-'.str_pad(
                    (string) $sequence,
                    4,
                    '0',
                    STR_PAD_LEFT
                ),
                'status' => InventoryAct::STATUS_DRAFT,
                'inventory_date' => $inventoryDate,
                'created_by' => $createdBy,
                'commission_members' => $commissionMembers,
                'notes' => $notes,
            ]);

            $this->stockService->createItems($act);

            return $act;
        });
    }

    public function approveAct(int $organizationId, int $actId, int $approvedBy): InventoryAct
    {
        return DB::transaction(function () use ($organizationId, $actId, $approvedBy): InventoryAct {
            $act = InventoryAct::query()
                ->where('organization_id', $organizationId)
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($actId);

            if ($act->status !== InventoryAct::STATUS_COMPLETED) {
                throw new InventoryApprovalStatusException;
            }

            foreach ($act->items as $item) {
                if (! $item->hasDiscrepancy()) {
                    continue;
                }

                $this->stockService->applyApprovedQuantity(
                    $act,
                    $item,
                    (float) ($item->actual_quantity ?? 0)
                );
            }

            $act->update([
                'status' => InventoryAct::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $approvedBy,
            ]);

            return $act;
        });
    }
}
