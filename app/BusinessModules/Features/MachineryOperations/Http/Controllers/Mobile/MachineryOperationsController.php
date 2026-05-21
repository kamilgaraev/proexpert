<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Controllers\Mobile;

use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryAssetResource;
use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryOperationRecordResource;
use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryShiftReportResource;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MachineryOperationsController extends Controller
{
    public function __construct(
        private readonly MachineryOperationsService $service,
    ) {
    }

    public function assets(Request $request): JsonResponse
    {
        try {
            $assets = $this->service->paginateAssets(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                [
                    'project_id' => $request->input('project_id'),
                    'status' => $request->input('status'),
                ]
            );

            return MobileResponse::success(MachineryAssetResource::collection($assets->getCollection()));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'assets.index');
        }
    }

    public function storeShift(Request $request): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'assignment_id' => ['nullable', 'integer'],
                'report_date' => ['required', 'date', 'before_or_equal:today'],
                'planned_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
                'actual_hours' => ['required', 'numeric', 'min:0', 'max:24'],
                'fuel_consumed' => ['required', 'numeric', 'min:0'],
                'meter_start' => ['nullable', 'numeric', 'min:0'],
                'meter_end' => ['nullable', 'numeric', 'min:0'],
                'work_description' => ['nullable', 'string', 'max:5000'],
            ]);

            return MobileResponse::success(
                new MachineryShiftReportResource($this->service->createShiftReport(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('machinery_operations.messages.shift_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('machinery_operations.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shifts.store');
        }
    }

    public function shifts(Request $request): JsonResponse
    {
        try {
            $shifts = $this->service->paginateShifts(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                [
                    'project_id' => $request->input('project_id'),
                    'asset_id' => $request->input('asset_id'),
                    'status' => $request->input('status'),
                ]
            );

            return MobileResponse::success(MachineryShiftReportResource::collection($shifts->getCollection()));
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shifts.index');
        }
    }

    public function submitShift(Request $request, int $id): JsonResponse
    {
        try {
            $shift = $this->service->findShift((int) $request->attributes->get('current_organization_id'), $id);

            if ($shift === null) {
                return MobileResponse::error(trans_message('machinery_operations.errors.shift_not_found'), 404);
            }

            return MobileResponse::success(new MachineryShiftReportResource($this->service->submitShift($shift)));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shifts.submit');
        }
    }

    public function storeDowntime(Request $request): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'shift_report_id' => ['nullable', 'integer'],
                'reason' => ['required', 'string', 'max:80'],
                'started_at' => ['required', 'date', 'before_or_equal:' . now()->toDateTimeString()],
                'ended_at' => ['nullable', 'date', 'after:started_at', 'before_or_equal:' . now()->toDateTimeString()],
                'duration_minutes' => ['required', 'integer', 'min:1'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            return MobileResponse::success(
                new MachineryOperationRecordResource($this->service->createDowntime(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                )),
                trans_message('machinery_operations.messages.downtime_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('machinery_operations.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'downtime.store');
        }
    }

    public function storeFuelIssue(Request $request): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'issued_at' => ['required', 'date', 'before_or_equal:' . now()->toDateTimeString()],
                'fuel_type' => ['required', 'string', 'max:80'],
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'unit' => ['required', 'string', 'max:20'],
                'cost' => ['nullable', 'numeric', 'min:0'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            return MobileResponse::success(
                new MachineryOperationRecordResource($this->service->createFuelIssue(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('machinery_operations.messages.fuel_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('machinery_operations.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'fuel.store');
        }
    }

    public function storeProductionRecord(Request $request): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'shift_report_id' => ['nullable', 'integer'],
                'recorded_at' => ['required', 'date', 'before_or_equal:' . now()->toDateTimeString()],
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'unit' => ['required', 'string', 'max:20'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            return MobileResponse::success(
                new MachineryOperationRecordResource($this->service->createProductionRecord(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('machinery_operations.messages.production_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('machinery_operations.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'production.store');
        }
    }

    private function failed(Request $request, \Throwable $exception, string $scope): JsonResponse
    {
        Log::error("machinery_operations.mobile.{$scope}.error", [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('machinery_operations.errors.action_failed'), 500);
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
            'asset_id.required' => trans_message('machinery_operations.validation.asset_required'),
            'project_id.required' => trans_message('machinery_operations.validation.project_required'),
            'report_date.required' => trans_message('machinery_operations.validation.report_date_required'),
            'report_date.before_or_equal' => trans_message('machinery_operations.validation.date_future'),
            'actual_hours.required' => trans_message('machinery_operations.validation.actual_hours_required'),
            'actual_hours.min' => trans_message('machinery_operations.validation.actual_hours_range'),
            'actual_hours.max' => trans_message('machinery_operations.validation.actual_hours_range'),
            'fuel_consumed.required' => trans_message('machinery_operations.validation.fuel_consumed_required'),
            'fuel_consumed.min' => trans_message('machinery_operations.validation.fuel_consumed_min'),
            'reason.required' => trans_message('machinery_operations.validation.downtime_reason_required'),
            'started_at.required' => trans_message('machinery_operations.validation.started_at_required'),
            'started_at.before_or_equal' => trans_message('machinery_operations.validation.date_future'),
            'ended_at.before_or_equal' => trans_message('machinery_operations.validation.date_future'),
            'ended_at.after' => trans_message('machinery_operations.validation.ended_after_started'),
            'duration_minutes.required' => trans_message('machinery_operations.validation.duration_required'),
            'duration_minutes.min' => trans_message('machinery_operations.validation.duration_positive'),
            'issued_at.required' => trans_message('machinery_operations.validation.issued_at_required'),
            'issued_at.before_or_equal' => trans_message('machinery_operations.validation.date_future'),
            'fuel_type.required' => trans_message('machinery_operations.validation.fuel_type_required'),
            'quantity.required' => trans_message('machinery_operations.validation.quantity_required'),
            'quantity.min' => trans_message('machinery_operations.validation.quantity_positive'),
            'unit.required' => trans_message('machinery_operations.validation.unit_required'),
            'recorded_at.required' => trans_message('machinery_operations.validation.recorded_at_required'),
            'recorded_at.before_or_equal' => trans_message('machinery_operations.validation.date_future'),
        ];
    }
}
