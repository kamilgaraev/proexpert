<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Http\Controllers\Mobile;

use App\BusinessModules\Features\ProductionLabor\Http\Resources\ProductionLaborOutputEntryResource;
use App\BusinessModules\Features\ProductionLabor\Http\Resources\ProductionLaborTimesheetResource;
use App\BusinessModules\Features\ProductionLabor\Http\Resources\ProductionLaborWorkOrderResource;
use App\BusinessModules\Features\ProductionLabor\Services\ProductionLaborService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class ProductionLaborController extends Controller
{
    public function __construct(private readonly ProductionLaborService $service)
    {
    }

    public function workOrders(Request $request): JsonResponse
    {
        try {
            $paginator = $this->service->paginateWorkOrders(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 50),
                $request->only(['project_id', 'status'])
            );

            return MobileResponse::success([
                'data' => ProductionLaborWorkOrderResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'work_orders.index');
        }
    }

    public function storeOutput(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'work_order_line_id' => ['required', 'integer'],
                'work_date' => ['required', 'date'],
                'quantity' => ['required', 'numeric', 'min:0.0001'],
                'hours' => ['nullable', 'numeric', 'min:0'],
                'comment' => ['nullable', 'string', 'max:2000'],
                'metadata' => ['nullable', 'array'],
            ]);

            return MobileResponse::success(
                new ProductionLaborOutputEntryResource($this->service->recordOutput(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated,
                    false
                )),
                trans_message('production_labor.messages.output_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'output.store');
        }
    }

    public function storeTimesheet(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'work_order_id' => ['required', 'integer'],
                'shift_date' => ['required', 'date'],
                'entries' => ['required', 'array', 'min:1'],
                'entries.*.work_order_line_id' => ['required', 'integer'],
                'entries.*.user_id' => ['nullable', 'integer'],
                'entries.*.worker_name' => ['nullable', 'string', 'max:255'],
                'entries.*.hours' => ['required', 'numeric', 'min:0.01'],
                'entries.*.safety_permit_reference' => ['nullable', 'string', 'max:120'],
            ]);

            return MobileResponse::success(
                new ProductionLaborTimesheetResource($this->service->createTimesheet(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('production_labor.messages.timesheet_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'timesheets.store');
        }
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('production_labor.mobile_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('production_labor.errors.unexpected'), 500);
    }
}
