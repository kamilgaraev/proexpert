<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Controllers;

use App\BusinessModules\Core\Mdm\Http\Requests\UpdateMdmQualityPolicyRequest;
use App\BusinessModules\Core\Mdm\Services\MdmQualityPolicyService;
use App\BusinessModules\Core\Mdm\Services\MdmReadService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MdmQualityPoliciesController extends MdmBaseController
{
    public function __construct(
        private readonly MdmReadService $readService,
        private readonly MdmQualityPolicyService $qualityPolicyService
    ) {}

    public function qualityPolicies(Request $request): JsonResponse
    {
        return $this->handle($request, 'MDM quality policies failed', 'mdm.errors.quality_policies_failed', fn (): JsonResponse => AdminResponse::success($this->readService->qualityPolicies($this->organizationId($request))));
    }

    public function updateQualityPolicy(UpdateMdmQualityPolicyRequest $request, string $entityType): JsonResponse
    {
        return $this->handle($request, 'MDM quality policy update failed', 'mdm.errors.quality_policy_save_failed', function () use ($request, $entityType): JsonResponse {
            abort_unless($this->readService->entityExists($entityType), 404);

            $policy = $this->qualityPolicyService->upsert($request->organizationId(), $entityType, $request->validated());

            return AdminResponse::success($policy, trans_message('mdm.messages.quality_policy_saved'));
        });
    }
}
