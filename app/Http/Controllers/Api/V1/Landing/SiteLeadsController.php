<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteLeadService;
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
        private readonly SiteLeadService $leadService
    ) {
    }

    public function indexForHolding(Request $request): JsonResponse
    {
        try {
            $site = $this->resolveHoldingSiteFromRequest($request);
            $user = Auth::user();

            if (!$site->canUserEdit($user)) {
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

            if (!$site->canUserEdit($user)) {
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

            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:255',
                'company' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:100',
                'message' => 'nullable|string|max:5000',
                'block_key' => 'nullable|string|max:255',
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
        } catch (\Throwable $e) {
            Log::error('Holding site public lead submit failed', [
                'host' => $request->getHost(),
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
}
