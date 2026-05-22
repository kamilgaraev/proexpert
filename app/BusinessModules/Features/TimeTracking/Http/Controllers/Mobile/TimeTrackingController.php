<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Http\Controllers\Mobile;

use App\BusinessModules\Features\TimeTracking\Http\Resources\MobileTimeEntryResource;
use App\BusinessModules\Features\TimeTracking\Services\MobileTimeTrackingService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TimeTrackingController extends Controller
{
    public function __construct(
        private readonly MobileTimeTrackingService $service,
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.view')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'project_id' => ['nullable', 'integer'],
                'date' => ['nullable', 'date_format:Y-m-d'],
                'status' => ['nullable', 'string', Rule::in(MobileTimeTrackingService::STATUSES)],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            $result = $this->service->paginateEntries(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $validated,
                min((int) $request->input('per_page', 20), 50)
            );
            $paginator = $result->paginator;

            return MobileResponse::success([
                'items' => MobileTimeEntryResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'summary' => $result->summary,
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'index');
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.view')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'date' => ['required', 'date_format:Y-m-d'],
                'project_id' => ['nullable', 'integer'],
            ]);
            $summary = $this->service->dailySummary(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $validated['date'],
                isset($validated['project_id']) ? (int) $validated['project_id'] : null
            );

            return MobileResponse::success([
                'date' => $summary['date'],
                'project_id' => $summary['project_id'],
                'entries' => MobileTimeEntryResource::collection($summary['entries'])->resolve(),
                'active_timer' => $summary['active_timer'] ? (new MobileTimeEntryResource($summary['active_timer']))->resolve() : null,
                'totals' => $summary['totals'],
                'approval_status' => $summary['approval_status'],
            ]);
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'daily_summary');
        }
    }

    public function show(Request $request, int $entry): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.view')) {
            return $denied;
        }

        try {
            return MobileResponse::success(new MobileTimeEntryResource($this->service->findEntry(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $entry
            )));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'show');
        }
    }

    public function store(Request $request): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.create')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, $this->entryRules(requireHours: true));

            return MobileResponse::success(
                new MobileTimeEntryResource($this->service->createManualEntry(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('time_tracking.mobile.messages.entry_created'),
                201
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'store');
        }
    }

    public function startTimer(Request $request): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.create')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, $this->entryRules(requireHours: false, requireStartTime: true));

            return MobileResponse::success(
                new MobileTimeEntryResource($this->service->startTimer(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('time_tracking.mobile.messages.timer_started'),
                201
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'start_timer');
        }
    }

    public function stopTimer(Request $request, int $entry): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.edit')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'end_time' => ['required', 'date_format:H:i'],
                'break_time' => ['required', 'numeric', 'min:0', 'max:24'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
            $model = $this->service->findEntry(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $entry
            );

            return MobileResponse::success(
                new MobileTimeEntryResource($this->service->stopTimer($model, (int) $request->user()?->id, $validated)),
                trans_message('time_tracking.mobile.messages.timer_stopped')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'stop_timer');
        }
    }

    public function submit(Request $request, int $entry): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.submit')) {
            return $denied;
        }

        try {
            $model = $this->service->findEntry(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $entry
            );

            return MobileResponse::success(
                new MobileTimeEntryResource($this->service->submitEntry($model)),
                trans_message('time_tracking.mobile.messages.entry_submitted')
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'submit');
        }
    }

    public function correction(Request $request, int $entry): JsonResponse
    {
        if ($denied = $this->ensurePermission($request, 'time_tracking.edit')) {
            return $denied;
        }

        if ($denied = $this->ensurePermission($request, 'time_tracking.submit')) {
            return $denied;
        }

        try {
            $validated = $this->validated($request, [
                'hours_worked' => ['required', 'numeric', 'min:0.01', 'max:24'],
                'correction_reason' => ['required', 'string', 'max:1000'],
                'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
                'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
                'break_time' => ['nullable', 'numeric', 'min:0', 'max:24'],
                'title' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:1000'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
            $model = $this->service->findEntry(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $entry
            );

            return MobileResponse::success(
                new MobileTimeEntryResource($this->service->submitCorrection($model, (int) $request->user()?->id, $validated)),
                trans_message('time_tracking.mobile.messages.correction_submitted')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailed($exception);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'correction');
        }
    }

    private function ensurePermission(Request $request, string $permission): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$this->authorizationService->can($user, $permission, [
            'organization_id' => (int) $request->attributes->get('current_organization_id'),
        ])) {
            return MobileResponse::error(
                trans_message('time_tracking.mobile.errors.permission_denied'),
                403,
                null,
                ['error_code' => 'PERMISSION_DENIED']
            );
        }

        return null;
    }

    private function entryRules(bool $requireHours, bool $requireStartTime = false): array
    {
        return [
            'project_id' => ['required', 'integer'],
            'work_type_id' => ['nullable', 'integer'],
            'task_id' => ['nullable', 'integer'],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'start_time' => [$requireStartTime ? 'required' : 'nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'hours_worked' => [$requireHours ? 'required' : 'prohibited', 'numeric', 'min:0.01', 'max:24'],
            'break_time' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_billable' => ['required', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, $this->validationMessages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function validationFailed(ValidationException $exception): JsonResponse
    {
        return MobileResponse::error(
            trans_message('time_tracking.mobile.errors.validation_failed'),
            422,
            $exception->errors()
        );
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('time_tracking.mobile_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('time_tracking.mobile.errors.action_failed'), 500);
    }

    private function validationMessages(): array
    {
        return [
            'date.required' => trans_message('time_tracking.mobile.validation.date_required'),
            'date.date_format' => trans_message('time_tracking.mobile.validation.date_invalid'),
            'work_date.required' => trans_message('time_tracking.mobile.validation.work_date_required'),
            'work_date.date_format' => trans_message('time_tracking.mobile.validation.work_date_invalid'),
            'work_date.before_or_equal' => trans_message('time_tracking.mobile.validation.work_date_future'),
            'project_id.required' => trans_message('time_tracking.mobile.validation.project_required'),
            'project_id.integer' => trans_message('time_tracking.mobile.validation.project_invalid'),
            'start_time.required' => trans_message('time_tracking.mobile.validation.start_time_required'),
            'start_time.date_format' => trans_message('time_tracking.mobile.validation.time_invalid'),
            'end_time.required' => trans_message('time_tracking.mobile.validation.end_time_required'),
            'end_time.date_format' => trans_message('time_tracking.mobile.validation.time_invalid'),
            'end_time.after' => trans_message('time_tracking.mobile.validation.end_time_after_start'),
            'hours_worked.required' => trans_message('time_tracking.mobile.validation.hours_required'),
            'hours_worked.prohibited' => trans_message('time_tracking.mobile.validation.hours_not_allowed_for_timer'),
            'hours_worked.numeric' => trans_message('time_tracking.mobile.validation.hours_invalid'),
            'hours_worked.min' => trans_message('time_tracking.mobile.validation.hours_invalid'),
            'hours_worked.max' => trans_message('time_tracking.mobile.validation.hours_too_large'),
            'break_time.required' => trans_message('time_tracking.mobile.validation.break_time_required'),
            'break_time.numeric' => trans_message('time_tracking.mobile.validation.break_time_invalid'),
            'break_time.min' => trans_message('time_tracking.mobile.validation.break_time_invalid'),
            'break_time.max' => trans_message('time_tracking.mobile.validation.break_time_too_large'),
            'title.required' => trans_message('time_tracking.mobile.validation.title_required'),
            'title.max' => trans_message('time_tracking.mobile.validation.title_too_long'),
            'is_billable.required' => trans_message('time_tracking.mobile.validation.is_billable_required'),
            'correction_reason.required' => trans_message('time_tracking.mobile.validation.correction_reason_required'),
            'correction_reason.max' => trans_message('time_tracking.mobile.validation.correction_reason_too_long'),
            'per_page.max' => trans_message('time_tracking.mobile.validation.per_page_max'),
        ];
    }
}
