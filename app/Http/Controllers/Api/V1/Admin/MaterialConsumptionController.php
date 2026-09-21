<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\MaterialConsumptionStatementService;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\MaterialConsumptionReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\MaterialConsumption\ApproveMaterialConsumptionRequest;
use App\Http\Requests\Api\V1\Admin\MaterialConsumption\StoreMaterialConsumptionFactRequest;
use App\Http\Requests\Api\V1\Admin\MaterialConsumption\StoreMaterialConsumptionRateRequest;
use App\Http\Requests\Api\V1\Admin\MaterialConsumption\StoreMaterialConsumptionStatementRequest;
use App\Http\Resources\Api\V1\Admin\MaterialConsumption\MaterialConsumptionFactResource;
use App\Http\Resources\Api\V1\Admin\MaterialConsumption\MaterialConsumptionRateResource;
use App\Http\Resources\Api\V1\Admin\MaterialConsumption\MaterialConsumptionStatementResource;
use App\Http\Responses\AdminResponse;
use App\Models\MaterialConsumptionFact;
use App\Models\MaterialConsumptionRate;
use App\Models\MaterialConsumptionStatement;
use App\Services\MaterialConsumption\MaterialConsumptionFactService;
use App\Services\MaterialConsumption\MaterialConsumptionRateService;
use App\Services\MaterialConsumption\MaterialConsumptionStatementExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

final class MaterialConsumptionController extends Controller
{
    public function __construct(
        private readonly MaterialConsumptionRateService $rateService,
        private readonly MaterialConsumptionFactService $factService,
        private readonly MaterialConsumptionStatementService $statementService,
        private readonly MaterialConsumptionStatementExportService $exportService,
    ) {
        $this->middleware('auth:api_admin');
        $this->middleware('organization.context');
    }

    public function storeRate(StoreMaterialConsumptionRateRequest $request): JsonResponse
    {
        try {
            $rate = $this->rateService->create(
                $this->organizationId($request),
                $request->validated(),
                (int) $request->user()?->id,
            );

            return AdminResponse::success(
                new MaterialConsumptionRateResource($rate),
                trans_message('material_consumption.rate_created'),
                201,
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.rate_create_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.create_failed'), 500);
        }
    }

    public function showRate(Request $request, mixed $rate): JsonResponse
    {
        try {
            return AdminResponse::success(new MaterialConsumptionRateResource(
                $this->findRate($request, $rate)
            ));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.rate_show_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.load_failed'), 500);
        }
    }

    public function approveRate(ApproveMaterialConsumptionRequest $request, mixed $rate): JsonResponse
    {
        try {
            $approved = $this->rateService->approve($this->findRate($request, $rate), (int) $request->user()?->id);

            return AdminResponse::success(
                new MaterialConsumptionRateResource($approved),
                trans_message('material_consumption.rate_approved'),
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.rate_approve_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.create_failed'), 500);
        }
    }

    public function storeFact(StoreMaterialConsumptionFactRequest $request): JsonResponse
    {
        try {
            $fact = $this->factService->record(
                $this->organizationId($request),
                $request->validated(),
                (int) $request->user()?->id,
            );

            return AdminResponse::success(
                new MaterialConsumptionFactResource($fact),
                trans_message('material_consumption.fact_created'),
                201,
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.fact_create_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.create_failed'), 500);
        }
    }

    public function showFact(Request $request, mixed $fact): JsonResponse
    {
        try {
            return AdminResponse::success(new MaterialConsumptionFactResource(
                $this->findFact($request, $fact)
            ));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.fact_show_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.load_failed'), 500);
        }
    }

    public function storeStatement(StoreMaterialConsumptionStatementRequest $request): JsonResponse
    {
        try {
            $statement = $this->statementService->create(
                $this->organizationId($request),
                $request->validated(),
                (int) $request->user()?->id,
            );

            return AdminResponse::success(
                new MaterialConsumptionStatementResource($statement),
                trans_message('material_consumption.statement_created'),
                201,
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.statement_create_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.create_failed'), 500);
        }
    }

    public function showStatement(Request $request, mixed $statement): JsonResponse
    {
        try {
            return AdminResponse::success(new MaterialConsumptionStatementResource(
                $this->findStatement($request, $statement)
            ));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.statement_show_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.load_failed'), 500);
        }
    }

    public function approveStatement(ApproveMaterialConsumptionRequest $request, mixed $statement): JsonResponse
    {
        try {
            $approved = $this->statementService->approve(
                $this->findStatement($request, $statement),
                (int) $request->user()?->id,
            );

            return AdminResponse::success(
                new MaterialConsumptionStatementResource($approved),
                trans_message('material_consumption.statement_approved'),
            );
        } catch (MaterialConsumptionReadinessException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->reasons);
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.statement_approve_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.create_failed'), 500);
        }
    }

    public function exportStatementXlsx(Request $request, mixed $statement): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->exportService->exportXlsx($this->findStatement($request, $statement))
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.statement_export_xlsx_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.export_failed'), 500);
        }
    }

    public function exportStatementPdf(Request $request, mixed $statement): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->exportService->exportPdf($this->findStatement($request, $statement))
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getCode());
        } catch (Throwable $exception) {
            Log::error('material_consumption.statement_export_pdf_failed', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('material_consumption.export_failed'), 500);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->user()?->current_organization_id;
    }

    private function findRate(Request $request, mixed $rate): MaterialConsumptionRate
    {
        $id = $rate instanceof MaterialConsumptionRate ? (int) $rate->id : (int) $rate;
        $model = MaterialConsumptionRate::query()
            ->whereKey($id)
            ->where('organization_id', $this->organizationId($request))
            ->first();
        if ($model === null) {
            throw new BusinessLogicException(trans_message('material_consumption.rate_not_found'), 404);
        }

        return $model;
    }

    private function findFact(Request $request, mixed $fact): MaterialConsumptionFact
    {
        $id = $fact instanceof MaterialConsumptionFact ? (int) $fact->id : (int) $fact;
        $model = MaterialConsumptionFact::query()
            ->whereKey($id)
            ->where('organization_id', $this->organizationId($request))
            ->first();
        if ($model === null) {
            throw new BusinessLogicException(trans_message('material_consumption.fact_not_found'), 404);
        }

        return $model;
    }

    private function findStatement(Request $request, mixed $statement): MaterialConsumptionStatement
    {
        $id = $statement instanceof MaterialConsumptionStatement ? (int) $statement->id : (int) $statement;
        $model = MaterialConsumptionStatement::query()
            ->whereKey($id)
            ->where('organization_id', $this->organizationId($request))
            ->first();
        if ($model === null) {
            throw new BusinessLogicException(trans_message('material_consumption.statement_not_found'), 404);
        }

        return $model;
    }
}
