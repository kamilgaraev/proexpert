<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSiteRevision;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteBuilderDataService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteRevisionService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SiteRevisionsController extends Controller
{
    public function __construct(
        private readonly SiteRevisionService $revisionService,
        private readonly SiteBuilderDataService $builderDataService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);

            if (!$site->canUserView(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success($this->revisionService->listForSite($site));
        } catch (\Throwable $e) {
            Log::error('Holding site revisions load failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.load_error'), 500);
        }
    }

    public function rollback(Request $request, int $revisionId): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSite($request);
            $revision = HoldingSiteRevision::query()
                ->where('holding_site_id', $site->id)
                ->where('id', $revisionId)
                ->firstOrFail();

            if (!$site->canUserPublish(Auth::user())) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->revisionService->rollback($site, $revision, Auth::user());

            return LandingResponse::success($this->builderDataService->getEditorPayload($site->fresh()), trans_message('holding_site_builder.published'));
        } catch (\Throwable $e) {
            Log::error('Holding site rollback failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'revision_id' => $revisionId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.publish_error'), 500);
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
