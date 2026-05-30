<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Landing;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceHiringOfferService;
use App\BusinessModules\ContractorMarketplace\Http\Requests\Landing\DeclineMarketplaceHiringOfferRequest;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceHiringOfferResource;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MarketplaceOfferInboxController extends Controller
{
    public function __construct(
        private readonly MarketplaceHiringOfferService $offerService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            $filters = $request->only(['project_id', 'status']);
            $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
            $offers = $this->offerService->listInbox($organizationId, $filters, $perPage);

            return LandingResponse::paginated(
                MarketplaceHiringOfferResource::collection($offers->getCollection()),
                [
                    'current_page' => $offers->currentPage(),
                    'last_page' => $offers->lastPage(),
                    'per_page' => $offers->perPage(),
                    'total' => $offers->total(),
                    'filters' => $filters,
                ],
                null,
                200,
                null,
                [
                    'first' => $offers->url(1),
                    'last' => $offers->url($offers->lastPage()),
                    'prev' => $offers->previousPageUrl(),
                    'next' => $offers->nextPageUrl(),
                ]
            );
        } catch (\Throwable $exception) {
            Log::error('Failed to list marketplace offer inbox', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.offer_list_error'), 500);
        }
    }

    public function show(Request $request, MarketplaceHiringOffer $offer): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            return LandingResponse::success(new MarketplaceHiringOfferResource(
                $this->offerService->showForContractorOrganization($offer, $organizationId)
            ));
        } catch (BusinessLogicException $exception) {
            return LandingResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to show marketplace offer inbox item', [
                'organization_id' => $organizationId,
                'offer_id' => $offer->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.offer_load_error'), 500);
        }
    }

    public function view(Request $request, MarketplaceHiringOffer $offer): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            return LandingResponse::success(new MarketplaceHiringOfferResource(
                $this->offerService->markViewed($offer, $organizationId, $this->resolveUser($request))
            ));
        } catch (BusinessLogicException $exception) {
            return LandingResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to mark marketplace offer viewed', [
                'organization_id' => $organizationId,
                'offer_id' => $offer->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.offer_view_error'), 500);
        }
    }

    public function accept(Request $request, MarketplaceHiringOffer $offer): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            return LandingResponse::success(
                new MarketplaceHiringOfferResource($this->offerService->accept($offer, $organizationId, $this->resolveUser($request))),
                trans_message('contractor_marketplace.offer_accepted')
            );
        } catch (BusinessLogicException $exception) {
            return LandingResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to accept marketplace hiring offer', [
                'organization_id' => $organizationId,
                'offer_id' => $offer->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.offer_accept_error'), 500);
        }
    }

    public function decline(DeclineMarketplaceHiringOfferRequest $request, MarketplaceHiringOffer $offer): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            return LandingResponse::success(
                new MarketplaceHiringOfferResource($this->offerService->decline(
                    $offer,
                    $organizationId,
                    $this->resolveUser($request),
                    $request->validated()['reason'] ?? null
                )),
                trans_message('contractor_marketplace.offer_declined')
            );
        } catch (BusinessLogicException $exception) {
            return LandingResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to decline marketplace hiring offer', [
                'organization_id' => $organizationId,
                'offer_id' => $offer->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.offer_decline_error'), 500);
        }
    }

    private function resolveOrganizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id);
    }

    private function resolveUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new BusinessLogicException(trans_message('contractor_marketplace.offer_access_denied'), 401);
        }

        return $user;
    }
}
