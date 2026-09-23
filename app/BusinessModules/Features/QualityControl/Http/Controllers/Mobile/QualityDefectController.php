<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Controllers\Mobile;

use App\BusinessModules\Features\QualityControl\Http\Requests\Mobile\MobileQualityDefectRequest;
use App\BusinessModules\Features\QualityControl\Http\Requests\Mobile\AssignQualityDefectRequest;
use App\BusinessModules\Features\QualityControl\Http\Resources\QualityDefectResource;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileProjectAccessResolver;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class QualityDefectController extends Controller
{
    public function __construct(
        private readonly QualityDefectService $service,
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    public function index(MobileQualityDefectRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $perPage = min((int) $request->input('per_page', 20), 100);
            $filters = $request->validated();
            $projectIds = $this->accessibleProjectIds($request);

            if ($projectIds === []) {
                return MobileResponse::error(trans_message('quality_control.errors.no_accessible_projects'), 403);
            }

            $filters['project_ids'] = $projectIds;

            $defects = $this->service->paginate($organizationId, $perPage, $filters);

            return MobileResponse::paginated(
                QualityDefectResource::collection($defects->getCollection()),
                [
                    'current_page' => $defects->currentPage(),
                    'per_page' => $defects->perPage(),
                    'total' => $defects->total(),
                    'last_page' => $defects->lastPage(),
                ]
            );
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
            $defect = $this->service->find($id, $organizationId, $this->accessibleProjectIds($request));

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

    public function assign(AssignQualityDefectRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validated();
            $defect = $this->findOrFail($request, $id, $organizationId);

            return MobileResponse::success(new QualityDefectResource($this->service->assignToProjectParticipant(
                $defect,
                (int) $validated['assigned_to'],
                (int) $request->user()->id,
                $validated['comment'] ?? null,
            )));
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('assign', $id, $e);
        }
    }

    public function assignees(Request $request, int $id): JsonResponse
    {
        try {
            $defect = $this->findOrFail(
                $request,
                $id,
                (int) $request->attributes->get('current_organization_id'),
            );
            return MobileResponse::success($this->service->eligibleAssignees($defect));
        } catch (\Throwable $e) {
            return $this->failedAction('show', $id, $e);
        }
    }

    public function store(MobileQualityDefectRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validated();
            $defect = $this->service->create($organizationId, (int) auth()->id(), $validated);

            return MobileResponse::success(
                new QualityDefectResource($defect),
                trans_message('quality_control.messages.created'),
                201
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

    public function start(MobileQualityDefectRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validated();
            $defect = $this->findOrFail($request, $id, $organizationId);

            return MobileResponse::success(new QualityDefectResource($this->service->start(
                $defect,
                (int) auth()->id(),
                $validated['comment'] ?? null
            )));
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('start', $id, $e);
        }
    }

    public function resolve(MobileQualityDefectRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validated();
            $defect = $this->findOrFail($request, $id, $organizationId);

            return MobileResponse::success(new QualityDefectResource($this->service->resolve(
                $defect,
                (int) auth()->id(),
                $validated
            )));
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('resolve', $id, $e);
        }
    }

    public function verify(MobileQualityDefectRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validated();
            $defect = $this->findOrFail($request, $id, $organizationId);

            return MobileResponse::success(new QualityDefectResource($this->service->verify(
                $defect,
                (int) auth()->id(),
                true,
                $validated['comment'] ?? null
            )));
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('verify', $id, $e);
        }
    }

    public function reject(MobileQualityDefectRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validated();
            $defect = $this->findOrFail($request, $id, $organizationId);

            return MobileResponse::success(new QualityDefectResource($this->service->reject(
                $defect,
                (int) auth()->id(),
                $validated['comment']
            )));
        } catch (DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failedAction('reject', $id, $e);
        }
    }

    private function findOrFail(Request $request, int $id, int $organizationId)
    {
        $defect = $this->service->find($id, $organizationId, $this->accessibleProjectIds($request));

        if ($defect === null) {
            throw new DomainException(trans_message('quality_control.errors.not_found'));
        }

        return $defect;
    }

    private function accessibleProjectIds(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return $this->projectAccess->ids(
            $user,
            (int) $request->attributes->get('current_organization_id')
        );
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

}
