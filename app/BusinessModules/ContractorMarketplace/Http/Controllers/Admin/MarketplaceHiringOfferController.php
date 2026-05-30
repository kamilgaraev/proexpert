<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceHiringOfferService;
use App\BusinessModules\ContractorMarketplace\Http\Requests\Admin\ReviewMarketplaceHiringOfferRequest;
use App\BusinessModules\ContractorMarketplace\Http\Requests\Admin\StoreMarketplaceHiringOfferRequest;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceHiringOfferResource;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MarketplaceHiringOfferController extends Controller
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
            $offers = $this->offerService->listSent($organizationId, $filters, $perPage);

            return AdminResponse::paginated(
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
            Log::error('Failed to list marketplace hiring offers', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.offer_list_error'), 500);
        }
    }

    public function store(StoreMarketplaceHiringOfferRequest $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            $offer = $this->offerService->createOffer(
                $organizationId,
                $this->resolveUser($request),
                $request->validated()
            );

            return AdminResponse::success(
                new MarketplaceHiringOfferResource($offer),
                trans_message('contractor_marketplace.offer_sent'),
                201
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to create marketplace hiring offer', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.offer_send_error'), 500);
        }
    }

    public function show(Request $request, MarketplaceHiringOffer $offer): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            return AdminResponse::success(new MarketplaceHiringOfferResource(
                $this->offerService->showForHiringOrganization($offer, $organizationId)
            ));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to show marketplace hiring offer', [
                'organization_id' => $organizationId,
                'offer_id' => $offer->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.offer_load_error'), 500);
        }
    }

    public function cancel(Request $request, MarketplaceHiringOffer $offer): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        $validator = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return AdminResponse::error(
                trans_message('contractor_marketplace.offer_validation_error'),
                422,
                $validator->errors()
            );
        }

        try {
            return AdminResponse::success(
                new MarketplaceHiringOfferResource($this->offerService->cancel(
                    $offer,
                    $organizationId,
                    $this->resolveUser($request),
                    $validator->validated()['reason'] ?? null
                )),
                trans_message('contractor_marketplace.offer_cancelled')
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to cancel marketplace hiring offer', [
                'organization_id' => $organizationId,
                'offer_id' => $offer->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.offer_cancel_error'), 500);
        }
    }

    public function review(ReviewMarketplaceHiringOfferRequest $request, MarketplaceHiringOffer $offer): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            return AdminResponse::success(
                new MarketplaceHiringOfferResource($this->offerService->review(
                    $offer,
                    $organizationId,
                    $this->resolveUser($request),
                    $request->validated()
                )),
                trans_message('contractor_marketplace.offer_review_saved')
            );
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to review marketplace hiring offer', [
                'organization_id' => $organizationId,
                'offer_id' => $offer->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.offer_review_error'), 500);
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
