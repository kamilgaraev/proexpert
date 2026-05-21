<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Controllers\Mobile;

use App\BusinessModules\Features\QualityControl\Http\Resources\QualityDefectResource;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\File;

final class QualityDefectController extends Controller
{
    public function __construct(
        private readonly QualityDefectService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 20), 100);
            $filters = $request->only([
                'status',
                'project_id',
                'assigned_to',
                'severity',
                'overdue',
                'sort_by',
                'sort_dir',
            ]);

            $defects = $this->service->paginate($organizationId, $perPage, $filters);

            return MobileResponse::success([
                'items' => QualityDefectResource::collection($defects->getCollection())->resolve(),
                'meta' => [
                    'current_page' => $defects->currentPage(),
                    'per_page' => $defects->perPage(),
                    'total' => $defects->total(),
                    'last_page' => $defects->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('quality_control.mobile.defects.index.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('quality_control.errors.index_failed'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $defect = $this->service->find($id, $organizationId);

            if ($defect === null) {
                return MobileResponse::error(trans_message('quality_control.errors.not_found'), 404);
            }

            return MobileResponse::success(new QualityDefectResource($defect));
        } catch (\Throwable $e) {
            Log::error('quality_control.mobile.defects.show.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('quality_control.errors.show_failed'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $this->validated($request, [
                'project_id' => ['required', 'integer'],
                'contractor_id' => ['nullable', 'integer'],
                'assigned_to' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'severity' => ['required', 'string', Rule::in(['minor', 'major', 'critical'])],
                'location_name' => ['nullable', 'string', 'max:255'],
                'schedule_task_id' => ['nullable', 'integer'],
                'construction_journal_entry_id' => ['nullable', 'integer'],
                'completed_work_id' => ['nullable', 'integer'],
                'due_date' => ['nullable', 'date'],
                'inspection_required' => ['required', 'boolean'],
                'metadata' => ['nullable', 'array'],
                'photos' => ['nullable', 'array'],
                'photos.*.type' => ['required_with:photos', 'string', Rule::in(['before', 'after', 'evidence', 'other'])],
                'photos.*.url' => ['nullable', 'required_without:photos.*.file', 'string', 'max:2000'],
                'photos.*.file' => ['nullable', 'required_without:photos.*.url', File::image()->max(10 * 1024)],
                'photos.*.caption' => ['nullable', 'string', 'max:255'],
                'photos.*.metadata' => ['nullable', 'array'],
            ]);
            $defect = $this->service->create($organizationId, (int) auth()->id(), $validated);

            return MobileResponse::success(
                new QualityDefectResource($defect),
                trans_message('quality_control.messages.created'),
                201
            );
        } catch (ValidationException $e) {
            return MobileResponse::error(
                trans_message('quality_control.errors.validation_failed'),
                422,
                $e->errors()
            );
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('quality_control.mobile.defects.store.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('quality_control.errors.store_failed'), 500);
        }
    }

    public function start(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $this->validated($request, ['comment' => ['nullable', 'string', 'max:1000']]);
            $defect = $this->findOrFail($id, $organizationId);

            return MobileResponse::success(new QualityDefectResource($this->service->start(
                $defect,
                (int) auth()->id(),
                $validated['comment'] ?? null
            )));
        } catch (ValidationException $e) {
            return MobileResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('start', $id, $e);
        }
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $this->validated($request, [
                'comment' => ['nullable', 'string', 'max:1000'],
                'photos' => ['nullable', 'array'],
                'photos.*.type' => ['required_with:photos', 'string'],
                'photos.*.url' => ['required_with:photos', 'string', 'max:2000'],
                'photos.*.caption' => ['nullable', 'string', 'max:255'],
                'photos.*.metadata' => ['nullable', 'array'],
            ]);
            $defect = $this->findOrFail($id, $organizationId);

            return MobileResponse::success(new QualityDefectResource($this->service->resolve(
                $defect,
                (int) auth()->id(),
                $validated
            )));
        } catch (ValidationException $e) {
            return MobileResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('resolve', $id, $e);
        }
    }

    private function findOrFail(int $id, int $organizationId)
    {
        $defect = $this->service->find($id, $organizationId);

        if ($defect === null) {
            throw new DomainException(trans_message('quality_control.errors.not_found'));
        }

        return $defect;
    }

    private function failedAction(string $action, int $id, \Throwable $e): JsonResponse
    {
        Log::error("quality_control.mobile.defects.{$action}.error", [
            'id' => $id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
        ]);

        return MobileResponse::error(trans_message("quality_control.errors.{$action}_failed"), 500);
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
            'project_id.required' => trans_message('quality_control.validation.project_required'),
            'title.required' => trans_message('quality_control.validation.title_required'),
            'severity.required' => trans_message('quality_control.validation.severity_required'),
            'severity.in' => trans_message('quality_control.validation.severity_invalid'),
            'inspection_required.required' => trans_message('quality_control.validation.inspection_required'),
            'photos.*.type.required_with' => trans_message('quality_control.validation.photo_type_required'),
            'photos.*.url.required_without' => trans_message('quality_control.validation.photo_required'),
            'photos.*.file.required_without' => trans_message('quality_control.validation.photo_required'),
        ];
    }
}
