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
            $validated = $request->validate([
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'assignment_id' => ['nullable', 'integer'],
                'report_date' => ['required', 'date'],
                'planned_hours' => ['nullable', 'numeric', 'min:0'],
                'actual_hours' => ['nullable', 'numeric', 'min:0'],
                'fuel_consumed' => ['nullable', 'numeric', 'min:0'],
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
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
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
            $validated = $request->validate([
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'shift_report_id' => ['nullable', 'integer'],
                'reason' => ['required', 'string', 'max:80'],
                'started_at' => ['required', 'date'],
                'ended_at' => ['nullable', 'date', 'after:started_at'],
                'duration_minutes' => ['nullable', 'integer', 'min:0'],
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
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'downtime.store');
        }
    }

    public function storeFuelIssue(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'asset_id' => ['required', 'integer'],
                'project_id' => ['required', 'integer'],
                'issued_at' => ['required', 'date'],
                'fuel_type' => ['required', 'string', 'max:80'],
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'unit' => ['nullable', 'string', 'max:20'],
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
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'fuel.store');
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
}
