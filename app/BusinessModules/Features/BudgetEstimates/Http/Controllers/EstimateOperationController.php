<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Controllers;

use App\BusinessModules\Features\BudgetEstimates\Services\Operations\EstimateOperationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Estimate\ResolveEstimateOperationRequest;
use App\Http\Requests\Admin\Estimate\StoreEstimateOperationRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EstimateOperationController extends Controller
{
    public function __construct(private readonly EstimateOperationService $operations)
    {
    }

    public function store(StoreEstimateOperationRequest $request, int $project): JsonResponse
    {
        return AdminResponse::success($this->operations->payload($this->operations->create($request->user(), $project, $request->validated())), null, 202);
    }

    public function show(Request $request, int $project, string $operation): JsonResponse
    {
        return AdminResponse::success($this->operations->show($request->user(), $project, $operation, max(1, $request->integer('page', 1)), $request->boolean('only_conflicts')));
    }

    public function resolve(ResolveEstimateOperationRequest $request, int $project, string $operation): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($this->operations->payload($this->operations->resolve($request->user(), $project, $operation, $data['resolutions'], (bool) ($data['refresh'] ?? false))), null, 202);
    }

    public function apply(Request $request, int $project, string $operation): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        validator(['key' => $key], ['key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/']])->validate();

        return AdminResponse::success($this->operations->payload($this->operations->apply($request->user(), $project, $operation, $key)), null, 202);
    }
}
