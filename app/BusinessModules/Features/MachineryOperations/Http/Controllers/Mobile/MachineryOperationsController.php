<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Controllers\Mobile;

use App\BusinessModules\Features\MachineryOperations\DTO\AssetRequestData;
use App\BusinessModules\Features\MachineryOperations\Http\Requests\CreateAssetRequest;
use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryAssetResource;
use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryOperationRecordResource;
use App\BusinessModules\Features\MachineryOperations\Http\Resources\MachineryShiftReportResource;
use App\BusinessModules\Features\MachineryOperations\Services\AssetDispatchService;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryIdempotencyService;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryOperationsService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileProjectAccessResolver;
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
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly AssetDispatchService $dispatch,
        private readonly MachineryIdempotencyService $idempotency,
    ) {}

    public function storeRequest(CreateAssetRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $this->assertProjectAccess($request, (int) $data['project_id']);

            return MobileResponse::success($this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()->id,
                $request->header('Idempotency-Key'),
                'asset_request.create',
                $data,
                fn () => $this->dispatch->request(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()->id,
                    new AssetRequestData(
                        projectId: (int) $data['project_id'],
                        plannedStartAt: (string) $data['planned_start_at'],
                        plannedEndAt: $data['planned_end_at'] ?? null,
                        purpose: (string) $data['purpose'],
                        priority: (string) ($data['priority'] ?? 'normal'),
                        scheduleTaskId: isset($data['schedule_task_id']) ? (int) $data['schedule_task_id'] : null,
                        requiredProfile: $data['required_profile'] ?? [],
                    ),
                ),
            ), null, 201);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        }
    }

    public function assets(Request $request): JsonResponse
    {
        try {
            $this->assertProjectAccess($request, $request->input('project_id'));
            $assets = $this->service->paginateAssets(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                [
                    'project_id' => $request->input('project_id'),
                    'project_ids' => $this->accessibleProjectIds($request),
                    'status' => $request->input('status'),
                ]
            );

            return MobileResponse::success(MachineryAssetResource::collection($assets->getCollection()));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
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
                'pre_shift_inspection' => ['required', 'array'],
                'pre_shift_inspection.result' => ['required', 'in:serviceable,restricted,unavailable'],
                'pre_shift_inspection.notes' => ['nullable', 'string', 'max:5000'],
                'pre_shift_inspection.evidence' => ['nullable', 'array'],
                'pre_shift_inspection.defects' => ['nullable', 'array', 'max:50'],
                'pre_shift_inspection.defects.*.code' => ['required', 'string', 'max:80'],
                'pre_shift_inspection.defects.*.severity' => ['required', 'in:low,medium,high,critical'],
                'pre_shift_inspection.defects.*.description' => ['required', 'string', 'max:5000'],
            ]);

            $shift = $this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $request->header('Idempotency-Key'),
                'shift.create',
                $validated,
                fn () => $this->service->createShiftReport(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                ),
            );

            return MobileResponse::success(
                new MachineryShiftReportResource($shift),
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
            $this->assertProjectAccess($request, $request->input('project_id'));
            $shifts = $this->service->paginateShifts(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                [
                    'project_id' => $request->input('project_id'),
                    'project_ids' => $this->accessibleProjectIds($request),
                    'asset_id' => $request->input('asset_id'),
                    'status' => $request->input('status'),
                ]
            );

            return MobileResponse::success(MachineryShiftReportResource::collection($shifts->getCollection()));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
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

            $this->assertProjectAccess($request, $shift->project_id);

            $submitted = $this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $request->header('Idempotency-Key'),
                'shift.submit',
                ['shift_id' => $id],
                fn () => $this->service->submitShift($shift),
            );

            return MobileResponse::success(new MachineryShiftReportResource($submitted));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shifts.submit');
        }
    }

    public function finishShift(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'actual_hours' => ['required', 'numeric', 'min:0', 'max:24'],
                'fuel_consumed' => ['required', 'numeric', 'min:0'],
                'meter_end' => ['nullable', 'numeric', 'min:0'],
                'work_description' => ['nullable', 'string', 'max:5000'],
                'post_shift_inspection' => ['required', 'array'],
                'post_shift_inspection.result' => ['required', 'in:serviceable,restricted,unavailable'],
                'post_shift_inspection.notes' => ['nullable', 'string', 'max:5000'],
                'post_shift_inspection.evidence' => ['nullable', 'array'],
                'post_shift_inspection.defects' => ['nullable', 'array', 'max:50'],
                'post_shift_inspection.defects.*.code' => ['required', 'string', 'max:80'],
                'post_shift_inspection.defects.*.severity' => ['required', 'in:low,medium,high,critical'],
                'post_shift_inspection.defects.*.description' => ['required', 'string', 'max:5000'],
            ]);
            $shift = $this->service->findShift((int) $request->attributes->get('current_organization_id'), $id);
            if ($shift === null) {
                return MobileResponse::error(trans_message('machinery_operations.errors.shift_not_found'), 404);
            }
            $this->assertProjectAccess($request, $shift->project_id);

            $finished = $this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $request->header('Idempotency-Key'),
                'shift.finish',
                ['shift_id' => $id, ...$validated],
                fn () => $this->service->finishShift(
                    $shift,
                    (int) $request->user()?->id,
                    $validated,
                ),
            );

            return MobileResponse::success(new MachineryShiftReportResource($finished));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('machinery_operations.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'shifts.finish');
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
                'started_at' => ['required', 'date', 'before_or_equal:'.now()->toDateTimeString()],
                'ended_at' => ['nullable', 'date', 'after:started_at', 'before_or_equal:'.now()->toDateTimeString()],
                'duration_minutes' => ['required', 'integer', 'min:1'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            $downtime = $this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $request->header('Idempotency-Key'),
                'downtime.create',
                $validated,
                fn () => $this->service->createDowntime(
                    (int) $request->attributes->get('current_organization_id'),
                    $validated
                ),
            );

            return MobileResponse::success(
                new MachineryOperationRecordResource($downtime),
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
                'shift_report_id' => ['required', 'integer'],
                'warehouse_id' => ['required', 'integer'],
                'material_id' => ['required', 'integer'],
                'issued_at' => ['required', 'date', 'before_or_equal:'.now()->toDateTimeString()],
                'fuel_type' => ['required', 'string', 'max:80'],
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'unit' => ['required', 'string', 'max:20'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);
            $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
            if ($idempotencyKey === '') {
                throw new DomainException(trans_message('machinery_operations.errors.idempotency_key_required'));
            }

            $fuelIssue = $this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $idempotencyKey,
                'fuel.create',
                $validated,
                fn () => $this->service->createFuelIssue(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $idempotencyKey,
                    $validated
                ),
            );

            return MobileResponse::success(
                new MachineryOperationRecordResource($fuelIssue),
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
                'recorded_at' => ['required', 'date', 'before_or_equal:'.now()->toDateTimeString()],
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'unit' => ['required', 'string', 'max:20'],
                'comment' => ['nullable', 'string', 'max:2000'],
            ]);

            $productionRecord = $this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $request->header('Idempotency-Key'),
                'production.create',
                $validated,
                fn () => $this->service->createProductionRecord(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                ),
            );

            return MobileResponse::success(
                new MachineryOperationRecordResource($productionRecord),
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

    public function maintenanceOrders(Request $request): JsonResponse
    {
        try {
            $this->assertProjectAccess($request, $request->input('project_id'));
            $orders = $this->service->paginateMaintenanceOrders(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                [
                    'project_id' => $request->input('project_id'),
                    'asset_id' => $request->input('asset_id'),
                    'status' => $request->input('status'),
                ],
            );

            return MobileResponse::success(MachineryOperationRecordResource::collection($orders->getCollection()));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'maintenance.index');
        }
    }

    public function completeMaintenanceOrder(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'completion_comment' => ['nullable', 'string', 'max:2000'],
                'inspection_result' => ['nullable', 'in:serviceable,restricted,unavailable'],
                'inspection_evidence' => ['nullable', 'array'],
            ]);
            $order = $this->service->findMaintenanceOrder((int) $request->attributes->get('current_organization_id'), $id);
            if ($order === null) {
                return MobileResponse::error(trans_message('machinery_operations.errors.maintenance_not_found'), 404);
            }
            $this->assertProjectAccess($request, $order->project_id);

            $completed = $this->idempotency->execute(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $request->header('Idempotency-Key'),
                'maintenance.complete',
                ['maintenance_order_id' => $id, ...$validated],
                fn () => $this->service->completeMaintenanceOrder(
                    $order,
                    (int) $request->user()?->id,
                    $validated['completion_comment'] ?? null,
                    (string) ($validated['inspection_result'] ?? 'serviceable'),
                    $validated['inspection_evidence'] ?? [],
                ),
            );

            return MobileResponse::success(new MachineryOperationRecordResource($completed));
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('machinery_operations.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'maintenance.complete');
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

        $validated = $validator->validated();
        $this->assertProjectAccess($request, $validated['project_id'] ?? null);

        return $validated;
    }

    private function assertProjectAccess(Request $request, mixed $projectId): void
    {
        if ($projectId === null || $projectId === '') {
            return;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            throw new DomainException(trans_message('machinery_operations.errors.project_not_found'));
        }

        $this->projectAccess->assert(
            $user,
            (int) $request->attributes->get('current_organization_id'),
            (int) $projectId,
            trans_message('machinery_operations.errors.project_not_found'),
        );
    }

    private function accessibleProjectIds(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return $this->projectAccess
            ->query($user, (int) $request->attributes->get('current_organization_id'))
            ->pluck('projects.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
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
