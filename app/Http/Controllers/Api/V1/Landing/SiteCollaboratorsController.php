<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSiteCollaborator;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteCollaboratorService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SiteCollaboratorsController extends Controller
{
    public function __construct(
        private readonly SiteCollaboratorService $collaboratorService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);

            if (!$site->canUserView(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success($this->collaboratorService->listForSite($site));
        } catch (\Throwable $e) {
            Log::error('Holding site collaborators load failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.load_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);

            if (!$site->canManageCollaborators(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|integer|exists:users,id',
                'role' => ['required', 'string', Rule::in(HoldingSiteCollaborator::ROLES)],
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $user = User::query()->findOrFail((int) $validator->validated()['user_id']);
            $this->collaboratorService->addCollaborator($site, $user, $validator->validated()['role'], Auth::user());

            return LandingResponse::success($this->collaboratorService->listForSite($site), trans_message('holding_site_builder.updated'), 201);
        } catch (\Throwable $e) {
            Log::error('Holding site collaborator create failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function update(Request $request, int $collaboratorId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $collaborator = HoldingSiteCollaborator::query()
                ->where('holding_site_id', $site->id)
                ->where('id', $collaboratorId)
                ->firstOrFail();

            if (!$site->canManageCollaborators(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'role' => ['required', 'string', Rule::in(HoldingSiteCollaborator::ROLES)],
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(trans_message('holding_site_builder.validation_error'), 422, $validator->errors());
            }

            $this->collaboratorService->updateCollaborator($collaborator, $validator->validated()['role']);

            return LandingResponse::success($this->collaboratorService->listForSite($site), trans_message('holding_site_builder.updated'));
        } catch (\Throwable $e) {
            Log::error('Holding site collaborator update failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'collaborator_id' => $collaboratorId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function destroy(Request $request, int $collaboratorId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $collaborator = HoldingSiteCollaborator::query()
                ->where('holding_site_id', $site->id)
                ->where('id', $collaboratorId)
                ->firstOrFail();

            if (!$site->canManageCollaborators(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->collaboratorService->removeCollaborator($collaborator);

            return LandingResponse::success($this->collaboratorService->listForSite($site), trans_message('holding_site_builder.updated'));
        } catch (\Throwable $e) {
            Log::error('Holding site collaborator delete failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'collaborator_id' => $collaboratorId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    private function resolveHoldingSite(Request $request): HoldingSite
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $organizationGroup = OrganizationGroup::query()
            ->where('parent_organization_id', $organizationId)
            ->firstOrFail();

        return HoldingSite::query()
            ->where('organization_group_id', $organizationGroup->id)
            ->firstOrFail();
    }
}
