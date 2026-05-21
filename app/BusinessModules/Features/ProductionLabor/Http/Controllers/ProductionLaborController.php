<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Http\Controllers;

use App\BusinessModules\Features\ProductionLabor\Http\Resources\ProductionLaborOutputEntryResource;
use App\BusinessModules\Features\ProductionLabor\Http\Resources\ProductionLaborPayrollAccrualResource;
use App\BusinessModules\Features\ProductionLabor\Http\Resources\ProductionLaborTimesheetResource;
use App\BusinessModules\Features\ProductionLabor\Http\Resources\ProductionLaborWorkOrderResource;
use App\BusinessModules\Features\ProductionLabor\Services\ProductionLaborService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductionLaborController extends Controller
{
    public function __construct(private readonly ProductionLaborService $service)
    {
    }

    public function workOrders(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateWorkOrders(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), ProductionLaborWorkOrderResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'work_orders.index');
        }
    }

    public function storeWorkOrder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->workOrderRules());

            return AdminResponse::success(
                new ProductionLaborWorkOrderResource($this->service->createWorkOrder(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('production_labor.messages.work_order_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'work_orders.store');
        }
    }

    public function issueWorkOrder(Request $request, int $id): JsonResponse
    {
        return $this->workOrderAction($request, $id, fn ($workOrder) => $this->service->issueWorkOrder($workOrder));
    }

    public function startWorkOrder(Request $request, int $id): JsonResponse
    {
        return $this->workOrderAction($request, $id, fn ($workOrder) => $this->service->startWorkOrder($workOrder));
    }

    public function submitWorkOrder(Request $request, int $id): JsonResponse
    {
        return $this->workOrderAction($request, $id, fn ($workOrder) => $this->service->submitWorkOrder($workOrder));
    }

    public function acceptWorkOrder(Request $request, int $id): JsonResponse
    {
        return $this->workOrderAction($request, $id, fn ($workOrder) => $this->service->acceptWorkOrder($workOrder, (int) $request->user()?->id));
    }

    public function returnWorkOrder(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->workOrderAction($request, $id, fn ($workOrder) => $this->service->returnWorkOrder($workOrder, $validated['reason']));
    }

    public function closeWorkOrder(Request $request, int $id): JsonResponse
    {
        return $this->workOrderAction($request, $id, fn ($workOrder) => $this->service->closeWorkOrder($workOrder));
    }

    public function cancelWorkOrder(Request $request, int $id): JsonResponse
    {
        return $this->workOrderAction($request, $id, fn ($workOrder) => $this->service->cancelWorkOrder($workOrder));
    }

    public function outputEntries(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateOutputEntries(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'work_order_id', 'status'])
            ), ProductionLaborOutputEntryResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'output.index');
        }
    }

    public function storeOutput(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'work_order_line_id' => ['required', 'integer'],
                'work_date' => ['required', 'date'],
                'quantity' => ['required', 'numeric', 'min:0.0001'],
                'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
                'comment' => ['nullable', 'string', 'max:2000'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new ProductionLaborOutputEntryResource($this->service->recordOutput(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated,
                    $request->boolean('allow_overrun')
                )),
                trans_message('production_labor.messages.output_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'output.store');
        }
    }

    public function storeTimesheet(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->timesheetRules());

            return AdminResponse::success(
                new ProductionLaborTimesheetResource($this->service->createTimesheet(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('production_labor.messages.timesheet_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'timesheets.store');
        }
    }

    public function timesheets(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateTimesheets(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'work_order_id', 'status'])
            ), ProductionLaborTimesheetResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'timesheets.index');
        }
    }

    public function payrollAccruals(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginatePayrollAccruals(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), ProductionLaborPayrollAccrualResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll.index');
        }
    }

    public function preparePayroll(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'work_order_id' => ['required', 'integer'],
                'period_start' => ['required', 'date'],
                'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            ]);

            return AdminResponse::success(
                ProductionLaborPayrollAccrualResource::collection($this->service->preparePayroll(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('production_labor.messages.payroll_prepared'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'payroll.prepare');
        }
    }

    public function reports(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->reports(
                (int) $request->attributes->get('current_organization_id'),
                $request->only(['project_id'])
            ));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'reports.index');
        }
    }

    private function workOrderAction(Request $request, int $id, callable $callback): JsonResponse
    {
        try {
            return AdminResponse::success(new ProductionLaborWorkOrderResource($callback($this->service->findWorkOrder(
                (int) $request->attributes->get('current_organization_id'),
                $id
            ))));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'work_orders.action');
        }
    }

    private function paginated(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return AdminResponse::paginated($resourceClass::collection($paginator->items()), [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('production_labor.admin_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('production_labor.errors.unexpected'), 500);
    }

    private function workOrderRules(): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'schedule_task_id' => ['nullable', 'integer'],
            'contractor_id' => ['nullable', 'integer'],
            'order_number' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:255'],
            'assignee_type' => ['required', Rule::in(['brigade', 'worker', 'contractor'])],
            'assignee_name' => ['nullable', 'string', 'max:255'],
            'planned_start_date' => ['nullable', 'date'],
            'planned_finish_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.work_type_id' => ['nullable', 'integer'],
            'lines.*.estimate_item_id' => ['nullable', 'integer'],
            'lines.*.schedule_task_id' => ['nullable', 'integer'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.unit' => ['nullable', 'string', 'max:40'],
            'lines.*.planned_quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.planned_hours' => ['nullable', 'numeric', 'min:0'],
            'lines.*.hour_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.pay_basis' => ['nullable', Rule::in(['volume', 'hours'])],
            'lines.*.requires_safety_permit' => ['nullable', 'boolean'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }

    private function timesheetRules(): array
    {
        return [
            'work_order_id' => ['required', 'integer'],
            'shift_date' => ['required', 'date'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.work_order_line_id' => ['required', 'integer'],
            'entries.*.user_id' => ['nullable', 'integer'],
            'entries.*.employee_id' => ['nullable', 'integer'],
            'entries.*.include_in_payroll' => ['nullable', 'boolean'],
            'entries.*.worker_name' => ['nullable', 'string', 'max:255'],
            'entries.*.hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
            'entries.*.safety_permit_reference' => ['nullable', 'string', 'max:120'],
            'entries.*.metadata' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
