<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceHiringOfferService;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MobileTeamExpansionService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AccessController $access,
        private readonly MarketplaceSearchService $marketplace,
        private readonly MarketplaceHiringOfferService $offers,
        private readonly BrigadeWorkflowService $brigadeWorkflow,
        private readonly MobileProjectAccessResolver $projects,
    ) {}

    public function searchContractors(User $actor, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertPermission($actor, $organizationId, 'contractor-marketplace', 'contractor_marketplace.search.view');
        $filters['page'] = max(1, (int) ($filters['page'] ?? 1));
        unset($filters['per_page']);

        return $this->marketplace->search($organizationId, $filters, $perPage);
    }

    public function showContractor(User $actor, int $organizationId, int $profileId): MarketplaceContractorProfile
    {
        $this->assertPermission($actor, $organizationId, 'contractor-marketplace', 'contractor_marketplace.profile.view');
        $profile = MarketplaceContractorProfile::query()->findOrFail($profileId);

        return $this->marketplace->showVisibleProfile($organizationId, $profile);
    }

    public function createHiringOffer(int $organizationId, User $actor, array $payload): MarketplaceHiringOffer
    {
        $this->assertPermission($actor, $organizationId, 'contractor-marketplace', 'contractor_marketplace.offers.create');

        return $this->offers->createOffer($organizationId, $actor, $payload);
    }

    public function searchBrigades(User $actor, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertPermission($actor, $organizationId, 'brigades', 'brigades.catalog.view');

        return BrigadeProfile::query()
            ->approved()
            ->with(['specializations', 'organization'])
            ->when(isset($filters['search']), static fn ($query) => $query->where(function ($inner) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $inner->where('name', 'like', $term)->orWhere('description', 'like', $term);
            }))
            ->when(isset($filters['specialization']), static fn ($query) => $query->whereHas('specializations', static fn ($specializations) => $specializations->where('name', 'ilike', '%'.$filters['specialization'].'%')))
            ->when(isset($filters['city']), static fn ($query) => $query->whereJsonContains('regions', $filters['city']))
            ->when(isset($filters['availability_status']), static fn ($query) => $query->where('availability_status', $filters['availability_status']))
            ->latest()
            ->paginate($perPage);
    }

    public function showBrigade(User $actor, int $organizationId, int $brigadeId): BrigadeProfile
    {
        $this->assertPermission($actor, $organizationId, 'brigades', 'brigades.catalog.view');

        return BrigadeProfile::query()
            ->approved()
            ->with(['specializations', 'organization'])
            ->findOrFail($brigadeId);
    }

    public function brigadeRequests(User $actor, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $projectIds = $this->permittedProjectIds($actor, $organizationId, 'brigades.requests.view', $filters['project_id'] ?? null);

        return BrigadeRequest::query()
            ->with(['project', 'contractorOrganization'])
            ->withCount('responses')
            ->where('contractor_organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function createBrigadeRequest(User $actor, int $organizationId, array $payload): BrigadeRequest
    {
        $this->permittedProjectIds($actor, $organizationId, 'brigades.requests.create', $payload['project_id'] ?? null);

        $model = BrigadeRequest::query()->create([
            ...$payload,
            'contractor_organization_id' => $organizationId,
            'status' => BrigadeStatuses::REQUEST_OPEN,
            'published_at' => now(),
        ]);

        return $model->load(['project', 'contractorOrganization']);
    }

    public function brigadeResponses(User $actor, int $organizationId, int $requestId, array $filters, int $perPage): LengthAwarePaginator
    {
        $brigadeRequest = BrigadeRequest::query()
            ->where('contractor_organization_id', $organizationId)
            ->findOrFail($requestId);
        $this->permittedProjectIds($actor, $organizationId, 'brigades.responses.view', $brigadeRequest->project_id);

        return BrigadeResponse::query()
            ->with(['request.project', 'request.contractorOrganization', 'brigade.specializations'])
            ->where('request_id', $brigadeRequest->id)
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function brigadeInvitations(User $actor, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $projectIds = $this->permittedProjectIds($actor, $organizationId, 'brigades.invitations.view', $filters['project_id'] ?? null);

        return BrigadeInvitation::query()
            ->with(['brigade.specializations', 'project', 'contractorOrganization'])
            ->where('contractor_organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->latest()
            ->paginate($perPage);
    }

    public function createBrigadeInvitation(User $actor, int $organizationId, array $payload): BrigadeInvitation
    {
        $this->permittedProjectIds($actor, $organizationId, 'brigades.invitations.create', $payload['project_id'] ?? null);

        return DB::transaction(function () use ($organizationId, $payload): BrigadeInvitation {
            $brigade = BrigadeProfile::query()
                ->approved()
                ->lockForUpdate()
                ->findOrFail((int) $payload['brigade_id']);
            $existing = BrigadeInvitation::query()
                ->where('brigade_id', $brigade->id)
                ->where('project_id', (int) $payload['project_id'])
                ->where('contractor_organization_id', $organizationId)
                ->where('status', BrigadeStatuses::INVITATION_PENDING)
                ->exists();
            if ($existing) {
                throw new BusinessLogicException(trans_message('brigades.invitation_already_pending'), 409);
            }

            $invitation = BrigadeInvitation::query()->create([
                ...$payload,
                'contractor_organization_id' => $organizationId,
                'status' => BrigadeStatuses::INVITATION_PENDING,
            ]);

            return $invitation->load(['brigade.specializations', 'project', 'contractorOrganization']);
        });
    }

    /** @return array{response: BrigadeResponse, assignment: BrigadeProjectAssignment} */
    public function approveBrigadeResponse(User $actor, int $organizationId, int $requestId, int $responseId): array
    {
        $request = BrigadeRequest::query()
            ->where('contractor_organization_id', $organizationId)
            ->findOrFail($requestId);
        $this->permittedProjectIds($actor, $organizationId, 'brigades.responses.approve', $request->project_id);

        return DB::transaction(function () use ($organizationId, $requestId, $responseId, $request): array {
            $brigadeRequest = BrigadeRequest::query()
                ->where('contractor_organization_id', $organizationId)
                ->where('project_id', $request->project_id)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();
            $response = BrigadeResponse::query()
                ->where('request_id', $brigadeRequest->id)
                ->whereKey($responseId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($brigadeRequest->status !== BrigadeStatuses::REQUEST_OPEN || $response->status !== BrigadeStatuses::RESPONSE_PENDING) {
                throw new BusinessLogicException(trans_message('brigades.response_invalid_status'), 409);
            }

            $response->setRelation('request', $brigadeRequest);
            $response->update(['status' => BrigadeStatuses::RESPONSE_APPROVED]);
            $brigadeRequest->update(['status' => BrigadeStatuses::REQUEST_IN_REVIEW]);
            $assignment = $this->brigadeWorkflow->createAssignmentFromResponse($response);
            $response->load(['request.project', 'request.contractorOrganization', 'brigade.specializations']);

            return ['response' => $response, 'assignment' => $assignment->load(['project', 'contractorOrganization', 'brigade.specializations'])];
        });
    }

    private function assertPermission(User $actor, int $organizationId, string $module, string $permission): void
    {
        if (!$this->access->hasModuleAccess($organizationId, $module)) {
            throw new BusinessLogicException(trans_message('mobile_companions.errors.permission_denied'), 403);
        }

        if (!$actor->belongsToOrganization($organizationId) || !$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new BusinessLogicException(trans_message('mobile_companions.errors.permission_denied'), 403);
        }
    }

    private function permittedProjectIds(User $actor, int $organizationId, string $permission, mixed $projectId): array
    {
        if (! $this->access->hasModuleAccess($organizationId, 'brigades') || ! $actor->belongsToOrganization($organizationId)) {
            throw new BusinessLogicException(trans_message('mobile_companions.errors.permission_denied'), 403);
        }

        $query = $this->projects->query($actor, $organizationId)
            ->where('projects.organization_id', $organizationId);
        if ($projectId !== null) {
            $query->whereKey((int) $projectId);
        }

        $ids = $query->pluck('projects.id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $this->authorization->can($actor, $permission, [
                'organization_id' => $organizationId,
                'project_id' => $id,
                'strict_project_scope' => true,
            ]))
            ->values()
            ->all();
        if ($projectId !== null && $ids === []) {
            throw new BusinessLogicException(trans_message('mobile_companions.errors.item_not_found'), 404);
        }

        return $ids;
    }
}
