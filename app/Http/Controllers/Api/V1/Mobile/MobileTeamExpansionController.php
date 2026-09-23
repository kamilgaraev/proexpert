<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\ContractorMarketplace\Http\Requests\Landing\StoreMarketplaceHiringOfferRequest;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceContractorListItemResource;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceContractorProfileResource;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceHiringOfferResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\MobileBrigadeCatalogIndexRequest;
use App\Http\Requests\Api\V1\Mobile\MobileBrigadeInvitationStoreRequest;
use App\Http\Requests\Api\V1\Mobile\MobileBrigadeListRequest;
use App\Http\Requests\Api\V1\Mobile\MobileBrigadeRequestStoreRequest;
use App\Http\Requests\Api\V1\Mobile\MobileContractorSearchRequest;
use App\Http\Responses\MobileResponse;
use App\Http\Resources\Brigades\BrigadeInvitationResource;
use App\Http\Resources\Brigades\BrigadeProfileResource;
use App\Http\Resources\Brigades\BrigadeRequestResource;
use App\Http\Resources\Brigades\BrigadeProjectAssignmentResource;
use App\Http\Resources\Brigades\BrigadeResponseResource;
use App\Models\User;
use App\Services\Mobile\MobileTeamExpansionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileTeamExpansionController extends Controller
{
    public function __construct(private readonly MobileTeamExpansionService $service) {}

    public function contractorIndex(MobileContractorSearchRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $this->service->searchContractors($this->user($request), $this->organizationId($request), $filters, $perPage);
        $items = MarketplaceContractorListItemResource::collection($paginator->getCollection())->resolve($request);

        return MobileResponse::paginated($items, $this->pagination($paginator));
    }

    public function contractorShow(Request $request, int $profile): JsonResponse
    {
        $item = $this->service->showContractor($this->user($request), $this->organizationId($request), $profile);

        return MobileResponse::success(['item' => (new MarketplaceContractorProfileResource($item))->resolve($request)]);
    }

    public function contractorInvite(StoreMarketplaceHiringOfferRequest $request): JsonResponse
    {
        $offer = $this->service->createHiringOffer($this->organizationId($request), $this->user($request), $request->validated());

        return MobileResponse::success(
            ['item' => (new MarketplaceHiringOfferResource($offer))->resolve($request)],
            trans_message('contractor_marketplace.offer_sent'),
            201,
        );
    }

    public function brigadeIndex(MobileBrigadeCatalogIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = $this->service->searchBrigades($this->user($request), $this->organizationId($request), $filters, $perPage);
        $items = BrigadeProfileResource::collection($paginator->getCollection())->resolve($request);

        return MobileResponse::paginated($items, $this->pagination($paginator));
    }

    public function brigadeShow(Request $request, int $brigade): JsonResponse
    {
        $item = $this->service->showBrigade($this->user($request), $this->organizationId($request), $brigade);

        return MobileResponse::success(['item' => (new BrigadeProfileResource($item))->resolve($request)]);
    }

    public function requestIndex(MobileBrigadeListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $this->service->brigadeRequests(
            $this->user($request),
            $this->organizationId($request),
            $filters,
            (int) ($filters['per_page'] ?? 20),
        );
        $items = BrigadeRequestResource::collection($paginator->getCollection())->resolve($request);

        return MobileResponse::paginated($items, $this->pagination($paginator));
    }

    public function requestStore(MobileBrigadeRequestStoreRequest $request): JsonResponse
    {
        $item = $this->service->createBrigadeRequest($this->user($request), $this->organizationId($request), $request->validated());

        return MobileResponse::success(['item' => (new BrigadeRequestResource($item))->resolve($request)], trans_message('brigades.request_created'), 201);
    }

    public function responseIndex(MobileBrigadeListRequest $request, int $brigadeRequest): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $this->service->brigadeResponses(
            $this->user($request),
            $this->organizationId($request),
            $brigadeRequest,
            $filters,
            (int) ($filters['per_page'] ?? 20),
        );
        $items = BrigadeResponseResource::collection($paginator->getCollection())->resolve($request);

        return MobileResponse::paginated($items, $this->pagination($paginator));
    }

    public function responseApprove(Request $request, int $brigadeRequest, int $response): JsonResponse
    {
        $result = $this->service->approveBrigadeResponse(
            $this->user($request),
            $this->organizationId($request),
            $brigadeRequest,
            $response,
        );

        return MobileResponse::success([
            'response' => (new BrigadeResponseResource($result['response']))->resolve($request),
            'assignment' => (new BrigadeProjectAssignmentResource($result['assignment']))->resolve($request),
        ], trans_message('brigades.response_approved'));
    }

    public function invitationIndex(MobileBrigadeListRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $paginator = $this->service->brigadeInvitations(
            $this->user($request),
            $this->organizationId($request),
            $filters,
            (int) ($filters['per_page'] ?? 20),
        );
        $items = BrigadeInvitationResource::collection($paginator->getCollection())->resolve($request);

        return MobileResponse::paginated($items, $this->pagination($paginator));
    }

    public function invitationStore(MobileBrigadeInvitationStoreRequest $request): JsonResponse
    {
        $item = $this->service->createBrigadeInvitation($this->user($request), $this->organizationId($request), $request->validated());

        return MobileResponse::success(['item' => (new BrigadeInvitationResource($item))->resolve($request)], trans_message('brigades.invitation_created'), 201);
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function user(Request $request): User
    {
        return $request->user();
    }

    /** @return array{current_page:int,last_page:int,per_page:int,total:int} */
    private function pagination(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
