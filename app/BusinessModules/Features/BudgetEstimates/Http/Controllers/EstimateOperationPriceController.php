<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\Services\Operations\EstimateOperationOptions;
use App\BusinessModules\Features\BudgetEstimates\Services\Operations\EstimatePriceListImporter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Estimate\ImportEstimatePriceListRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateOperationPriceController extends Controller
{
    public function __construct(private readonly EstimatePriceListImporter $importer, private readonly EstimateOperationOptions $options)
    {
    }

    public function options(Request $request, int $project): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return AdminResponse::success($this->options->get($request->user(), $project, $data['date']));
    }

    public function inspect(ImportEstimatePriceListRequest $request, int $project): JsonResponse
    {
        return AdminResponse::success($this->importer->inspect($request->user(), $project, $request->file('file'), (int) $request->input('header_row', 1)));
    }

    public function store(ImportEstimatePriceListRequest $request, int $project): JsonResponse
    {
        $list = $this->importer->import($request->user(), $project, $request->file('file'), $request->validated());

        return AdminResponse::success(['id' => $list->id, 'name' => $list->name, 'row_count' => count($list->entries), 'checksum' => $list->checksum], null, 201);
    }
}
