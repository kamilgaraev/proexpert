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
use Illuminate\Support\Facades\Validator;
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
            $validated = $this->validated($request, [
                'work_order_line_id' => ['required', 'integer'],
                'work_date' => ['required', 'date', 'before_or_equal:today'],
                'quantity' => ['required', 'numeric', 'min:0.0001'],
                'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
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
            return MobileResponse::error(
                trans_message('production_labor.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'output.store');
        }
    }

    public function storeTimesheet(Request $request): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'work_order_id' => ['required', 'integer'],
                'shift_date' => ['required', 'date', 'before_or_equal:today'],
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
            return MobileResponse::error(
                trans_message('production_labor.errors.validation_failed'),
                422,
                $exception->errors()
            );
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

    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, $this->validationMessages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function validationMessages(): array
    {
        return [
            'work_order_line_id.required' => trans_message('production_labor.validation.work_order_line_required'),
            'work_date.required' => trans_message('production_labor.validation.work_date_required'),
            'work_date.before_or_equal' => trans_message('production_labor.validation.date_future'),
            'quantity.required' => trans_message('production_labor.validation.quantity_required'),
            'quantity.min' => trans_message('production_labor.validation.quantity_positive'),
            'hours.required' => trans_message('production_labor.validation.hours_required'),
            'hours.min' => trans_message('production_labor.validation.hours_range'),
            'hours.max' => trans_message('production_labor.validation.hours_range'),
            'work_order_id.required' => trans_message('production_labor.validation.work_order_required'),
            'shift_date.required' => trans_message('production_labor.validation.shift_date_required'),
            'shift_date.before_or_equal' => trans_message('production_labor.validation.date_future'),
            'entries.required' => trans_message('production_labor.validation.entries_required'),
            'entries.min' => trans_message('production_labor.validation.entries_required'),
            'entries.*.work_order_line_id.required' => trans_message('production_labor.validation.work_order_line_required'),
            'entries.*.hours.required' => trans_message('production_labor.validation.hours_required'),
            'entries.*.hours.min' => trans_message('production_labor.validation.hours_range'),
            'entries.*.hours.max' => trans_message('production_labor.validation.hours_range'),
        ];
    }
}
