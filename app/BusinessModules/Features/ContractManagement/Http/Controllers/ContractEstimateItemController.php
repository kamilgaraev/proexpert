<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Http\Controllers;

use App\BusinessModules\Features\ContractManagement\Http\Requests\AttachEstimateItemsRequest;
use App\BusinessModules\Features\ContractManagement\Http\Requests\DetachEstimateItemsRequest;
use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class ContractEstimateItemController extends Controller
{
    public function __construct(
        private readonly ContractEstimateService $service
    ) {}

    public function index(Request $request, Contract $contract)
    {
        try {
            $estimateId = $request->query('estimate_id') ? (int) $request->query('estimate_id') : null;
            $items = $this->service->getItemsForContract($contract, $estimateId);

            return AdminResponse::success(
                ContractEstimateItemResource::collection($items)
            );
        } catch (\Exception $e) {
            Log::error('contract_estimate_items.index_failed', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
            return AdminResponse::error('Ошибка при загрузке позиций сметы', 500);
        }
    }

    public function attach(AttachEstimateItemsRequest $request, Contract $contract)
    {
        try {
            $estimate = Estimate::findOrFail($request->integer('estimate_id'));

            if ($estimate->organization_id !== $contract->organization_id) {
                return AdminResponse::error('Смета принадлежит другой организации', 422);
            }

            $attached = $this->service->attachItems(
                $contract,
                $estimate,
                $request->input('item_ids')
            );

            return AdminResponse::success(
                ContractEstimateItemResource::collection($attached),
                'Позиции успешно привязаны к договору'
            );
        } catch (\Exception $e) {
            Log::error('contract_estimate_items.attach_failed', [
                'contract_id'  => $contract->id,
                'estimate_id'  => $request->integer('estimate_id'),
                'error'        => $e->getMessage(),
            ]);
            return AdminResponse::error('Ошибка при привязке позиций', 500);
        }
    }

    public function detach(DetachEstimateItemsRequest $request, Contract $contract)
    {
        try {
            $this->service->detachItems($contract, $request->input('item_ids'));

            return AdminResponse::success(null, 'Позиции успешно отвязаны от договора');
        } catch (\Exception $e) {
            Log::error('contract_estimate_items.detach_failed', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
            return AdminResponse::error('Ошибка при отвязке позиций', 500);
        }
    }

    public function available(Request $request, Contract $contract)
    {
        try {
            $estimateId = $request->query('estimate_id');
            if (!$estimateId) {
                return AdminResponse::error('Укажите estimate_id', 422);
            }

            $estimate = Estimate::findOrFail((int) $estimateId);

            $linkedItemIds = \App\Models\ContractEstimateItem::where('contract_id', $contract->id)
                ->pluck('estimate_item_id')
                ->toArray();

            $items = \App\Models\EstimateItem::where('estimate_id', $estimate->id)
                ->whereNull('parent_work_id')
                ->whereNotIn('id', $linkedItemIds)
                ->with(['measurementUnit', 'childItems'])
                ->get();

            return AdminResponse::success($items->map(fn($item) => [
                'id'              => $item->id,
                'position_number' => $item->position_number,
                'name'            => $item->name,
                'item_type'       => $item->item_type instanceof \App\Enums\EstimatePositionItemType
                    ? $item->item_type->value
                    : $item->item_type,
                'quantity_total'  => (float) $item->quantity_total,
                'unit_price'      => (float) $item->unit_price,
                'total_amount'    => (float) $item->total_amount,
                'children_count'  => $item->childItems->count(),
                'measurement_unit' => $item->measurementUnit
                    ? ['id' => $item->measurementUnit->id, 'short_name' => $item->measurementUnit->short_name]
                    : null,
            ]));
        } catch (\Exception $e) {
            Log::error('contract_estimate_items.available_failed', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
            return AdminResponse::error('Ошибка при загрузке доступных позиций', 500);
        }
    }

    public function summary(Contract $contract)
    {
        try {
            $summary = $this->service->getSummary($contract);
            return AdminResponse::success($summary);
        } catch (\Exception $e) {
            Log::error('contract_estimate_items.summary_failed', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
            return AdminResponse::error('Ошибка при загрузке сводки', 500);
        }
    }

    public function projectEstimates(Contract $contract)
    {
        try {
            $estimates = Estimate::where('project_id', $contract->project_id)
                ->where('organization_id', $contract->organization_id)
                ->withCount(['items as linked_items_count' => function ($query) use ($contract) {
                    $query->whereHas('contractLinks', function ($q) use ($contract) {
                        $q->where('contract_id', $contract->id);
                    });
                }])
                ->get();

            return AdminResponse::success(
                $estimates->map(fn($estimate) => [
                    'id'                 => $estimate->id,
                    'name'               => $estimate->name,
                    'number'             => $estimate->number,
                    'is_linked'          => $estimate->contract_id === $contract->id,
                    'linked_items_count' => $estimate->linked_items_count,
                ]),
                'Сметы проекта успешно загружены'
            );
        } catch (\Exception $e) {
            Log::error('contract_estimate_items.project_estimates_failed', [
                'contract_id' => $contract->id,
                'error'       => $e->getMessage(),
            ]);
            return AdminResponse::error('Ошибка при загрузке смет проекта', 500);
        }
    }
}
