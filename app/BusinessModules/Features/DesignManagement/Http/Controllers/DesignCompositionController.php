<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignCompositionRevisionResource;
use App\BusinessModules\Features\DesignManagement\Http\Requests\PreviewDesignCompositionRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignCompositionExclusionRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignCompositionRevisionRequest;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Services\DesignCompositionService;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DesignCompositionController extends Controller
{
    public function __construct(
        private readonly DesignCompositionService $composition,
        private readonly DesignManagementService $packages,
    ) {}

    public function preview(PreviewDesignCompositionRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            return AdminResponse::success(['effective_composition' => $this->composition->preview($this->organizationId($request), $request->user(), $data)]);
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    public function store(StoreDesignCompositionRevisionRequest $request, int $packageId): JsonResponse
    {
        $package = $this->packages->findPackage($this->organizationId($request), $packageId);
        if ($package === null) {
            return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
        }

        try {
            $data = $request->validated();

            return AdminResponse::success(new DesignCompositionRevisionResource($this->composition->createRevision($package, $request->user(), $data)), trans_message('design_composition.messages.saved'), 201);
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $packageId): JsonResponse
    {
        $package = $this->packages->findPackage($this->organizationId($request), $packageId);
        if ($package === null) {
            return AdminResponse::error(trans_message('design_management.errors.package_not_found'), 404);
        }

        $revision = $this->composition->current($package, $request->user());

        return AdminResponse::success(['revision' => $revision ? new DesignCompositionRevisionResource($revision) : null]);
    }

    public function approve(Request $request, int $revisionId): JsonResponse
    {
        return $this->transition($request, $revisionId, 'approve');
    }

    public function needsReview(Request $request, int $revisionId): JsonResponse
    {
        return $this->transition($request, $revisionId, 'needs_review');
    }

    public function exclude(StoreDesignCompositionExclusionRequest $request, int $revisionId): JsonResponse
    {
        $revision = $this->revision($request, $revisionId);
        if ($revision === null) {
            return AdminResponse::error(trans_message('design_composition.errors.revision_not_found'), 404);
        }

        try {
            $data = $request->validated();
            $exclusion = $this->composition->exclude($revision, $request->user(), $data);

            return AdminResponse::success(['id' => $exclusion->id, 'item_key' => $exclusion->item_key, 'reason' => $exclusion->reason, 'created_at' => $exclusion->created_at?->toIso8601String()]);
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    private function transition(Request $request, int $revisionId, string $action): JsonResponse
    {
        $revision = $this->revision($request, $revisionId);
        if ($revision === null) {
            return AdminResponse::error(trans_message('design_composition.errors.revision_not_found'), 404);
        }

        try {
            if ($action === 'approve') {
                $result = $this->composition->approve($revision, $request->user());
            } else {
                $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
                $result = $this->composition->needsReview($revision, $request->user(), $data['reason']);
            }

            return AdminResponse::success(new DesignCompositionRevisionResource($result));
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('design_composition.errors.invalid'), 422, $e->errors());
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    private function revision(Request $request, int $id): ?DesignCompositionRevision
    {
        return DesignCompositionRevision::query()->where('organization_id', $this->organizationId($request))->with(['author', 'approvedBy', 'exclusions.author'])->find($id);
    }

    private function organizationId(Request $request): int
    {
        return (int) ($request->user()?->current_organization_id ?? 0);
    }
}
