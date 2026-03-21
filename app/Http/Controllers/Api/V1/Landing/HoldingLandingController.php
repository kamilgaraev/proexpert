<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteBuilderDataService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HoldingLandingController extends Controller
{
    public function __construct(
        private readonly SiteManagementService $siteService,
        private readonly SiteBuilderDataService $builderDataService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $organizationGroup = $this->resolveOrganizationGroup($request);
            $user = Auth::user();

            if (!$this->canUserEditLanding($user, $organizationGroup)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $site = $this->siteService->getOrCreateHoldingLanding($organizationGroup, $user);

            return LandingResponse::success(
                $this->builderDataService->getEditorPayload($site)
            );
        } catch (\Throwable $e) {
            Log::error('Holding site builder load failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.load_error'), 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $organizationGroup = $this->resolveOrganizationGroup($request);
            $site = $this->siteService->getOrCreateHoldingLanding($organizationGroup, Auth::user());
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $validator = Validator::make($request->all(), [
                'domain' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('holding_sites', 'domain')->ignore($site->id),
                ],
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'logo_url' => 'nullable|string|max:2048',
                'favicon_url' => 'nullable|string|max:2048',
                'theme_config' => 'nullable|array',
                'theme_config.primary_color' => 'nullable|string|max:32',
                'theme_config.secondary_color' => 'nullable|string|max:32',
                'theme_config.accent_color' => 'nullable|string|max:32',
                'theme_config.background_color' => 'nullable|string|max:32',
                'theme_config.text_color' => 'nullable|string|max:32',
                'theme_config.font_family' => 'nullable|string|max:100',
                'seo_meta' => 'nullable|array',
                'seo_meta.title' => 'nullable|string|max:255',
                'seo_meta.description' => 'nullable|string|max:500',
                'seo_meta.keywords' => 'nullable|string|max:500',
                'seo_meta.og_title' => 'nullable|string|max:255',
                'seo_meta.og_description' => 'nullable|string|max:500',
                'seo_meta.og_image' => 'nullable|string|max:2048',
                'analytics_config' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return LandingResponse::error(
                    trans_message('holding_site_builder.validation_error'),
                    422,
                    $validator->errors()
                );
            }

            $this->siteService->updateSiteSettings($site, $validator->validated(), $user);

            return LandingResponse::success(
                $this->builderDataService->getEditorPayload($site->fresh()),
                trans_message('holding_site_builder.updated')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site builder update failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.update_error'), 500);
        }
    }

    public function publish(Request $request): JsonResponse
    {
        try {
            $organizationGroup = $this->resolveOrganizationGroup($request);
            $site = $this->siteService->getOrCreateHoldingLanding($organizationGroup, Auth::user());
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->siteService->publishSite($site, $user);

            return LandingResponse::success(
                $this->builderDataService->getEditorPayload($site->fresh()),
                trans_message('holding_site_builder.published')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site builder publish failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error($e->getMessage() ?: trans_message('holding_site_builder.publish_error'), 500);
        }
    }

    private function resolveOrganizationGroup(Request $request): OrganizationGroup
    {
        $organizationId = $request->attributes->get('current_organization_id');

        return OrganizationGroup::query()
            ->where('parent_organization_id', $organizationId)
            ->firstOrFail();
    }

    private function canUserEditLanding($user, OrganizationGroup $organizationGroup): bool
    {
        return $organizationGroup->parentOrganization->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('is_owner', true)
            ->exists();
    }
}
