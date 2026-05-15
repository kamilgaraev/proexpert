<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Controllers;

use App\BusinessModules\Features\QualityControl\Http\Requests\StoreQualityDefectRequest;
use App\BusinessModules\Features\QualityControl\Http\Resources\QualityDefectResource;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            $perPage = min((int) $request->input('per_page', 15), 100);
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

            return AdminResponse::paginated(
                QualityDefectResource::collection($defects->getCollection()),
                [
                    'current_page' => $defects->currentPage(),
                    'per_page' => $defects->perPage(),
                    'total' => $defects->total(),
                    'last_page' => $defects->lastPage(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('quality_control.defects.index.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('quality_control.errors.index_failed'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $defect = $this->service->find($id, $organizationId);

            if ($defect === null) {
                return AdminResponse::error(trans_message('quality_control.errors.not_found'), 404);
            }

            return AdminResponse::success(new QualityDefectResource($defect));
        } catch (\Throwable $e) {
            Log::error('quality_control.defects.show.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('quality_control.errors.show_failed'), 500);
        }
    }

    public function store(StoreQualityDefectRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $defect = $this->service->create($organizationId, (int) auth()->id(), $request->validated());

            return AdminResponse::success(
                new QualityDefectResource($defect),
                trans_message('quality_control.messages.created'),
                201
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('quality_control.defects.store.error', [
                'user_id' => auth()->id(),
                'payload' => $request->validated(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('quality_control.errors.store_failed'), 500);
        }
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'assigned_to' => ['required', 'integer'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ]);
            $defect = $this->findOrFail($id, $organizationId);

            return AdminResponse::success(new QualityDefectResource($this->service->assign(
                $defect,
                (int) $validated['assigned_to'],
                (int) auth()->id(),
                $validated['comment'] ?? null
            )));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('assign', $id, $e);
        }
    }

    public function start(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
            $defect = $this->findOrFail($id, $organizationId);

            return AdminResponse::success(new QualityDefectResource($this->service->start(
                $defect,
                (int) auth()->id(),
                $validated['comment'] ?? null
            )));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('start', $id, $e);
        }
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'comment' => ['nullable', 'string', 'max:1000'],
                'photos' => ['nullable', 'array'],
                'photos.*.type' => ['required_with:photos', 'string', Rule::in(['before', 'after', 'evidence', 'other'])],
                'photos.*.url' => ['nullable', 'required_without:photos.*.file', 'string', 'max:2000'],
                'photos.*.file' => ['nullable', 'required_without:photos.*.url', File::image()->max(10 * 1024)],
                'photos.*.caption' => ['nullable', 'string', 'max:255'],
                'photos.*.metadata' => ['nullable', 'array'],
            ]);
            $defect = $this->findOrFail($id, $organizationId);

            return AdminResponse::success(new QualityDefectResource($this->service->resolve(
                $defect,
                (int) auth()->id(),
                $validated
            )));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('resolve', $id, $e);
        }
    }

    public function verify(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'accepted' => ['required', 'boolean'],
                'comment' => ['nullable', 'string', 'max:1000'],
            ]);
            $defect = $this->findOrFail($id, $organizationId);

            return AdminResponse::success(new QualityDefectResource($this->service->verify(
                $defect,
                (int) auth()->id(),
                (bool) $validated['accepted'],
                $validated['comment'] ?? null
            )));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('verify', $id, $e);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'comment' => ['required', 'string', 'max:1000'],
            ]);
            $defect = $this->findOrFail($id, $organizationId);

            return AdminResponse::success(new QualityDefectResource($this->service->reject(
                $defect,
                (int) auth()->id(),
                $validated['comment']
            )));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('reject', $id, $e);
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
        Log::error("quality_control.defects.{$action}.error", [
            'id' => $id,
            'user_id' => auth()->id(),
            'error' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message("quality_control.errors.{$action}_failed"), 500);
    }
}
