<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Controllers;

use App\BusinessModules\Core\Mdm\Http\Requests\ListMdmDuplicatesRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\MergeMdmDuplicateRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\ResolveMdmDuplicateRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\SyncMdmRequest;
use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\BusinessModules\Core\Mdm\Services\MdmMergeService;
use App\BusinessModules\Core\Mdm\Services\MdmReadService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class MdmDuplicatesController extends MdmBaseController
{
    public function __construct(
        private readonly MdmReadService $readService,
        private readonly MdmDuplicateDetectionService $duplicateDetectionService,
        private readonly MdmMergeService $mergeService
    ) {}

    public function duplicates(ListMdmDuplicatesRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM duplicate list failed', 'mdm.errors.duplicates_failed', fn (): JsonResponse => $this->paginated($this->readService->duplicates($request->organizationId(), $request->validated())));
    }

    public function scanDuplicates(SyncMdmRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM duplicate scan failed', 'mdm.errors.duplicates_failed', function () use ($request): JsonResponse {
            $result = $this->duplicateDetectionService->scanOrganization($request->organizationId(), $request->validated('entity_type'), $request->user()?->id);

            return AdminResponse::success($result, trans_message('mdm.messages.duplicates_scanned'));
        });
    }

    public function resolveDuplicate(ResolveMdmDuplicateRequest $request, MdmDuplicateGroup $duplicateGroup): JsonResponse
    {
        return $this->handle($request, 'MDM duplicate resolve failed', 'mdm.errors.duplicate_resolve_failed', function () use ($request, $duplicateGroup): JsonResponse {
            $validated = $request->validated();
            $resolved = $this->duplicateDetectionService->resolve($duplicateGroup, $validated['decision'], $validated['master_entity_id'] ?? null, $request->user()?->id, $validated['note'] ?? null);

            return AdminResponse::success($resolved->load('members'), trans_message('mdm.messages.duplicate_resolved'));
        });
    }

    public function mergePlan(MergeMdmDuplicateRequest $request, MdmDuplicateGroup $duplicateGroup): JsonResponse
    {
        return $this->handle($request, 'MDM merge plan failed', 'mdm.errors.merge_failed', function () use ($request, $duplicateGroup): JsonResponse {
            $run = $this->mergeService->plan($duplicateGroup->load('members'), (int) $request->validated('master_entity_id'));

            return AdminResponse::success($run, trans_message('mdm.messages.merge_plan_ready'));
        });
    }

    public function mergeApply(MergeMdmDuplicateRequest $request, MdmDuplicateGroup $duplicateGroup): JsonResponse
    {
        return $this->handle($request, 'MDM merge apply failed', 'mdm.errors.merge_failed', function () use ($request, $duplicateGroup): JsonResponse {
            $run = $this->mergeService->apply($duplicateGroup->load('members'), (int) $request->validated('master_entity_id'), $request->user()?->id);

            return AdminResponse::success($run, trans_message('mdm.messages.merge_applied'));
        });
    }
}
