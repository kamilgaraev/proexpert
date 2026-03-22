<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteLeadService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\OrganizationGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SiteLeadsController extends Controller
{
    public function __construct(
        private readonly SiteLeadService $leadService,
        private readonly SiteManagementService $siteService
    ) {
    }

    public function indexForHolding(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSiteFromRequest($request);
            $user = Auth::user();

            if (!$site->canUserView($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success(
                $this->leadService->leadsForSite($site, $request->only(['status'])),
                trans_message('holding_site_builder.leads.loaded')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site leads load failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.leads.load_error'), 500);
        }
    }

    public function summaryForHolding(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSiteFromRequest($request);
            $user = Auth::user();

            if (!$site->canUserView($user)) {
                return LandingResponse::error(trans_message('holding_site_builder.access_denied'), 403);
            }

            return LandingResponse::success(
                $this->leadService->getSummary($site),
                trans_message('holding_site_builder.leads.summary_loaded')
            );
        } catch (\Throwable $e) {
            Log::error('Holding site leads summary failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.leads.summary_error'), 500);
        }
    }

    public function storePublic(Request $request): JsonResponse
    {
        try {
            $holding = $request->attributes->get('holding');
            if (!$holding) {
                return LandingResponse::error(trans_message('holding_site_builder.public.not_found'), 404);
            }

            $site = HoldingSite::query()
                ->where('organization_group_id', $holding->id)
                ->where('is_active', true)
                ->firstOrFail();

            return $this->storeLeadForSite($request, $site);
        } catch (\Throwable $e) {
            Log::error('Holding site public lead submit failed', [
                'host' => $request->getHost(),
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.leads.create_error'), 500);
        }
    }

    public function storeByDomain(Request $request): JsonResponse
    {
        try {
            $site = $this->resolvePublicSiteFromRequest($request);

            if (!$site) {
                return LandingResponse::error(trans_message('holding_site_builder.public.not_found'), 404);
            }

            return $this->storeLeadForSite($request, $site);
        } catch (\Throwable $e) {
            Log::error('Holding site public lead submit by domain failed', [
                'site_domain' => $request->input('site_domain'),
                'origin' => $request->headers->get('origin'),
                'referer' => $request->headers->get('referer'),
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('holding_site_builder.leads.create_error'), 500);
        }
    }

    private function resolveHoldingSiteFromRequest(Request $request): HoldingSite
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $organizationGroup = OrganizationGroup::query()
            ->where('parent_organization_id', $organizationId)
            ->firstOrFail();

        return HoldingSite::query()
            ->where('organization_group_id', $organizationGroup->id)
            ->firstOrFail();
    }

    private function storeLeadForSite(Request $request, HoldingSite $site): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:5000',
            'holding_site_page_id' => 'nullable|integer|exists:holding_site_pages,id',
            'block_key' => 'nullable|string|max:255',
            'section_key' => 'nullable|string|max:255',
            'locale_code' => 'nullable|string|max:12',
            'source_page' => 'nullable|string|max:255',
            'source_url' => 'nullable|string|max:2048',
            'form_payload' => 'nullable|array',
            'metadata' => 'nullable|array',
            'website' => 'nullable|string|max:255',
            'utm_source' => 'nullable|string|max:255',
            'utm_medium' => 'nullable|string|max:255',
            'utm_campaign' => 'nullable|string|max:255',
            'utm_content' => 'nullable|string|max:255',
            'utm_term' => 'nullable|string|max:255',
            'site_domain' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return LandingResponse::error(
                trans_message('holding_site_builder.validation_error'),
                422,
                $validator->errors()
            );
        }

        if (
            empty($request->input('name'))
            && empty($request->input('email'))
            && empty($request->input('phone'))
            && empty($request->input('message'))
        ) {
            return LandingResponse::error(trans_message('holding_site_builder.leads.contact_required'), 422);
        }

        if (!$this->leadService->ensureRateLimit($site, $request)) {
            return LandingResponse::error(trans_message('holding_site_builder.leads.rate_limited'), 429);
        }

        $lead = $this->leadService->submitLead($site, $validator->validated(), $request);

        return LandingResponse::success(
            $this->leadService->serializeLead($lead),
            trans_message('holding_site_builder.leads.created'),
            201
        );
    }

    private function resolvePublicSiteFromRequest(Request $request): ?HoldingSite
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
}
