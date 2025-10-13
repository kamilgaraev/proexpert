<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Контроллер для инвентаризации
 */
class InventoryController extends Controller
{
    /**
     * Получить список актов инвентаризации
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        
        $acts = InventoryAct::where('organization_id', $organizationId)
            ->with(['warehouse', 'creator'])
            ->when($request->input('warehouse_id'), function ($q, $warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            })
            ->when($request->input('status'), function ($q, $status) {
                $q->where('status', $status);
            })
            ->orderBy('inventory_date', 'desc')
            ->paginate(20);
        
        return response()->json([
            'success' => true,
            'data' => $acts,
        ]);
    }

    /**
     * Создать акт инвентаризации
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:organization_warehouses,id',
            'inventory_date' => 'required|date',
            'commission_members' => 'nullable|array',
            'commission_members.*' => 'exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $organizationId = $request->user()->current_organization_id;
        
        DB::beginTransaction();
        try {
            // Генерируем номер акта
            $actNumber = 'INV-' . date('Ymd') . '-' . str_pad(
                InventoryAct::where('organization_id', $organizationId)->count() + 1,
                4,
                '0',
                STR_PAD_LEFT
            );
            
            $act = InventoryAct::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $validated['warehouse_id'],
                'act_number' => $actNumber,
                'status' => InventoryAct::STATUS_DRAFT,
                'inventory_date' => $validated['inventory_date'],
                'created_by' => $request->user()->id,
                'commission_members' => $validated['commission_members'] ?? [],
                'notes' => $validated['notes'] ?? null,
            ]);
            
            // Создаем позиции акта на основе текущих остатков
            $balances = WarehouseBalance::where('organization_id', $organizationId)
                ->where('warehouse_id', $validated['warehouse_id'])
                ->where('available_quantity', '>', 0)
                ->get();
            
            foreach ($balances as $balance) {
                InventoryActItem::create([
                    'inventory_act_id' => $act->id,
                    'material_id' => $balance->material_id,
                    'expected_quantity' => $balance->available_quantity,
                    'unit_price' => $balance->average_price,
                    'location_code' => $balance->location_code,
                    'batch_number' => $balance->batch_number,
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'data' => $act->load('items.material'),
                'message' => 'Акт инвентаризации создан',
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Получить акт инвентаризации
     */
    public function show(int $id): JsonResponse
    {
        $act = InventoryAct::with(['warehouse', 'creator', 'approver', 'items.material'])
            ->findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $act,
        ]);
    }

    /**
     * Начать инвентаризацию
     */
    public function start(int $id): JsonResponse
    {
        $act = InventoryAct::findOrFail($id);
        
        if ($act->status !== InventoryAct::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Акт уже начат или завершен',
            ], 400);
        }
        
        $act->update([
            'status' => InventoryAct::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
        
        return response()->json([
            'success' => true,
            'data' => $act,
            'message' => 'Инвентаризация начата',
        ]);
    }

    /**
     * Обновить фактическое количество в позиции акта
     */
    public function updateItem(Request $request, int $actId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'actual_quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        $item = InventoryActItem::where('inventory_act_id', $actId)
            ->findOrFail($itemId);
        
        $item->update([
            'actual_quantity' => $validated['actual_quantity'],
            'notes' => $validated['notes'] ?? null,
        ]);
        
        // Рассчитываем разницу
        $item->calculateDifference();
        
        return response()->json([
            'success' => true,
            'data' => $item,
            'message' => 'Позиция обновлена',
        ]);
    }

    /**
     * Завершить инвентаризацию
     */
    public function complete(int $id): JsonResponse
    {
        $act = InventoryAct::with('items')->findOrFail($id);
        
        if ($act->status !== InventoryAct::STATUS_IN_PROGRESS) {
            return response()->json([
                'success' => false,
                'message' => 'Акт не в процессе инвентаризации',
            ], 400);
        }
        
        // Проверяем что все позиции заполнены
        $unfilledItems = $act->items->filter(fn($item) => $item->actual_quantity === null)->count();
        
        if ($unfilledItems > 0) {
            return response()->json([
                'success' => false,
                'message' => "Не все позиции заполнены. Осталось: {$unfilledItems}",
            ], 400);
        }
        
        // Подсчитываем сводку
        $summary = [
            'total_items' => $act->items->count(),
            'items_with_discrepancy' => $act->items->filter(fn($i) => $i->hasDiscrepancy())->count(),
            'total_difference_value' => $act->items->sum('total_value'),
        ];
        
        $act->update([
            'status' => InventoryAct::STATUS_COMPLETED,
            'completed_at' => now(),
            'summary' => $summary,
        ]);
        
        return response()->json([
            'success' => true,
            'data' => $act,
            'message' => 'Инвентаризация завершена',
        ]);
    }

    /**
     * Утвердить акт инвентаризации и применить корректировки
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $act = InventoryAct::with('items')->findOrFail($id);
        
        if ($act->status !== InventoryAct::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'Акт должен быть завершен перед утверждением',
            ], 400);
        }
        
        DB::beginTransaction();
        try {
            // Применяем корректировки к остаткам
            foreach ($act->items as $item) {
                if ($item->hasDiscrepancy()) {
                    $balance = WarehouseBalance::where('organization_id', $act->organization_id)
                        ->where('warehouse_id', $act->warehouse_id)
                        ->where('material_id', $item->material_id)
                        ->first();
                    
                    if ($balance) {
                        $balance->available_quantity = $item->actual_quantity;
                        $balance->last_movement_at = now();
                        $balance->save();
                    }
                }
            }
            
            $act->update([
                'status' => InventoryAct::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'data' => $act,
                'message' => 'Акт утвержден, корректировки применены',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

