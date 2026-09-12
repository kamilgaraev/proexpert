<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\Http\Requests\SaveEstimateFinanceRequest;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceExport;
use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;

class EstimateFinanceController extends Controller
{
    public function __construct(private readonly EstimateFinanceService $finance, private readonly EstimateFinanceExport $export) {}

    public function show(Request $request, int $project, int $estimate): mixed
    {
        return AdminResponse::success($this->finance->report($request->user(), $project, $estimate, $request->string('basis', 'with_vat')->toString()));
    }

    public function project(Request $request, int $project): mixed
    {
        return AdminResponse::success($this->finance->projectReport($request->user(), $project, $request->string('basis', 'with_vat')->toString()));
    }

    public function item(Request $request, int $project, int $estimate, int $item): mixed
    {
        return AdminResponse::success($this->finance->itemReport($request->user(), $project, $estimate, $item, $request->string('basis', 'with_vat')->toString()));
    }

    public function preview(SaveEstimateFinanceRequest $request, int $project, int $estimate): mixed
    {
        return AdminResponse::success($this->finance->preview($request->user(), $project, $estimate, $request->validated()));
    }

    public function save(SaveEstimateFinanceRequest $request, int $project, int $estimate): mixed
    {
        return AdminResponse::success($this->finance->save($request->user(), $project, $estimate, $request->validated()));
    }

    public function export(Request $request, int $project, ?int $estimate = null): mixed
    {
        return $this->export->download($request->user(), $project, $estimate, $request->string('basis', 'with_vat')->toString());
    }
}
