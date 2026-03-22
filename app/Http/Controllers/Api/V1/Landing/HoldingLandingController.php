<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteBuilderDataService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
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
            $site = $this->siteService->getOrCreateHoldingLanding($organizationGroup, $user);

            if (!$site->canUserView($user) && !$this->canUserEditLanding($user, $organizationGroup)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

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

            if (!$site->canUserPublish($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            $this->siteService->publishSite($site, $user);

            return LandingResponse::success(
                $this->builderDataService->getEditorPayload($site->fresh()),
                trans_message('holding_site_builder.published')
            );
        } catch (\InvalidArgumentException $e) {
            Log::warning('Holding site builder publish validation failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                $e->getMessage() ?: trans_message('holding_site_builder.validation_error'),
                422
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

    public function publicSiteData(Request $request): JsonResponse
    {
        try {
            $site = $this->resolvePublicSiteFromRequest($request);

            if (!$site) {
                return LandingResponse::error(trans_message('holding_site_builder.public.not_found'), 404);
            }

            $path = (string) $request->query('path', '/');
            $requestedLocale = $request->query('locale');

            if ($this->isValidPreview($request, $site)) {
                return LandingResponse::success($this->builderDataService->buildLiveDraftPayload($site, $path, is_string($requestedLocale) ? $requestedLocale : null));
            }

            $payload = $this->builderDataService->buildPublishedPayload($site, $path, is_string($requestedLocale) ? $requestedLocale : null);

            if (empty($payload['page']) && empty($payload['blocks']) && empty($payload['blog']['current_article'])) {
                return LandingResponse::error(trans_message('holding_site_builder.public.not_published'), 404);
            }

            return LandingResponse::success($payload);
        } catch (\Throwable $e) {
            Log::error('Holding public site data load failed', [
                'site_domain' => $request->query('site_domain'),
                'origin' => $request->headers->get('origin'),
                'referer' => $request->headers->get('referer'),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.public.load_error'), 500);
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

    private function resolvePublicSiteFromRequest(Request $request)
    {
        $siteDomain = $this->resolvePublicDomain($request);

        if (!$siteDomain) {
            return null;
        }

        return $this->siteService->getSiteByDomain($siteDomain);
    }

    private function resolvePublicDomain(Request $request): ?string
    {
        $siteDomain = trim((string) $request->input('site_domain', $request->query('site_domain', '')));
        if ($siteDomain !== '') {
            return $siteDomain;
        }

        $sourceUrl = (string) $request->input('source_url', '');
        if ($sourceUrl !== '') {
            $host = parse_url($sourceUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        foreach (['origin', 'referer'] as $headerName) {
            $headerValue = (string) $request->headers->get($headerName, '');
            if ($headerValue === '') {
                continue;
            }

            $host = parse_url($headerValue, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return null;
    }

    private function isValidPreview(Request $request, HoldingSite $site): bool
    {
        if ($request->get('preview') !== 'true') {
            return false;
        }

        $token = $request->get('token');
        if (!$token) {
            return false;
        }

        return $site->isValidPreviewToken($token);
    }
}
