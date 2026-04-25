<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateCoverageService;
use App\BusinessModules\Features\ContractManagement\Http\Requests\AttachEstimateItemsRequest;
use App\BusinessModules\Features\ContractManagement\Http\Requests\DetachEstimateItemsRequest;
use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\Http\Resources\Api\V1\Admin\Contract\ContractEstimateItemResource;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class ContractEstimateItemController extends Controller
{
    public function __construct(
        private readonly ContractEstimateService $service,
        private readonly EstimateCoverageService $coverageService
    ) {}

    public function index(Request $request, Contract $contract)
    {
        try {
            $estimateId = $request->query('estimate_id') ? (int) $request->query('estimate_id') : null;
            $items = $this->service->getItemsForContract($contract, $estimateId);

            return AdminResponse::success(ContractEstimateItemResource::collection($items));
        } catch (\Throwable $e) {
            Log::error('contract_estimate_items.index_failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error('Ошибка при загрузке позиций сметы', 500);
        }
    }

    public function attach(AttachEstimateItemsRequest $request, Contract $contract)
    {
        try {
            $estimate = Estimate::query()->findOrFail($request->integer('estimate_id'));

            if (
                $estimate->organization_id !== $contract->organization_id
                || $estimate->project_id !== $contract->project_id
            ) {
                return AdminResponse::error('Смета должна принадлежать той же организации и проекту', 422);
            }

            $attached = $this->service->attachItems(
                $contract,
                $estimate,
                $request->input('item_ids'),
                $request->boolean('include_vat')
            );

            return AdminResponse::success(
                ContractEstimateItemResource::collection($attached),
                'Позиции успешно привязаны к договору'
            );
        } catch (\Throwable $e) {
            Log::error('contract_estimate_items.attach_failed', [
                'contract_id' => $contract->id,
                'estimate_id' => $request->integer('estimate_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error('Ошибка при привязке позиций', 500);
        }
    }

    public function detach(DetachEstimateItemsRequest $request, Contract $contract)
    {
        try {
            $this->service->detachItems($contract, $request->input('item_ids'));

            return AdminResponse::success(null, 'Позиции успешно отвязаны от договора');
        } catch (\Throwable $e) {
            Log::error('contract_estimate_items.detach_failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
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

            $estimate = Estimate::query()->findOrFail((int) $estimateId);
            if (
                $estimate->organization_id !== $contract->organization_id
                || $estimate->project_id !== $contract->project_id
            ) {
                return AdminResponse::error('Смета должна принадлежать той же организации и проекту', 422);
            }

            $linkedItemIds = ContractEstimateItem::query()
                ->where('contract_id', $contract->id)
                ->pluck('estimate_item_id')
                ->toArray();

            $items = EstimateItem::query()
                ->where('estimate_id', $estimate->id)
                ->whereNotIn('id', $linkedItemIds)
                ->whereNull('parent_work_id')
                ->whereIn('item_type', ['work', 'material', 'equipment', 'machinery', 'labor'])
                ->where(function ($query) {
                    $query->where('is_not_accounted', false)
                        ->orWhereNull('is_not_accounted');
                })
                ->with(['measurementUnit', 'childItems'])
                ->get();

            return AdminResponse::success($items->map(fn (EstimateItem $item) => [
                'id' => $item->id,
                'position_number' => $item->position_number,
                'name' => $item->name,
                'item_type' => $item->item_type?->value ?? $item->item_type,
                'quantity_total' => (float) $item->quantity_total,
                'unit_price' => (float) $item->unit_price,
                'total_amount' => (float) $item->total_amount,
                'children_count' => $item->childItems->count(),
                'measurement_unit' => $item->measurementUnit
                    ? ['id' => $item->measurementUnit->id, 'short_name' => $item->measurementUnit->short_name]
                    : null,
            ]));
        } catch (\Throwable $e) {
            Log::error('contract_estimate_items.available_failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error('Ошибка при загрузке доступных позиций', 500);
        }
    }

    public function summary(Contract $contract)
    {
        try {
            return AdminResponse::success($this->coverageService->getContractCoverageSummary($contract));
        } catch (\Throwable $e) {
            Log::error('contract_estimate_items.summary_failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error('Ошибка при загрузке сводки', 500);
        }
    }

    public function projectEstimates(Contract $contract)
    {
        try {
            $estimates = Estimate::query()
                ->where('project_id', $contract->project_id)
                ->where('organization_id', $contract->organization_id)
                ->get()
                ->map(function (Estimate $estimate) use ($contract) {
                    $coverage = $this->coverageService->getCoverageForEstimate($estimate);
                    $contractCoverage = collect($coverage['contracts'])->firstWhere('contract_id', $contract->id);

                    return [
                        'id' => $estimate->id,
                        'name' => $estimate->name,
                        'number' => $estimate->number,
                        'coverage_status' => $contractCoverage['coverage_status'] ?? 'not_linked',
                        'linked_items_count' => $contractCoverage['linked_items_count'] ?? 0,
                        'total_items' => $coverage['total_items'] ?? $coverage['total_work_items'],
                        'total_work_items' => $coverage['total_items'] ?? $coverage['total_work_items'],
                        'is_linked' => $contractCoverage !== null,
                    ];
                });

            return AdminResponse::success($estimates, 'Сметы проекта успешно загружены');
        } catch (\Throwable $e) {
            Log::error('contract_estimate_items.project_estimates_failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error('Ошибка при загрузке смет проекта', 500);
        }
    }
}
