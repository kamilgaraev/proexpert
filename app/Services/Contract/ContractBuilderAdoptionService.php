<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ContractBuilderAdoptionService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly ContractOrganizationViewService $views, private readonly ContractBuilderLegacyCompatibility $compatibility) {}

    public function preview(User $actor, int $organizationId, int $contractId): array
    {
        if ((int) $actor->current_organization_id !== $organizationId
            || !$this->authorization->can($actor, 'contracts.revisions.adopt', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $organizationId, $contractId): array {
            $contract = Contract::whereKey($contractId)->where('organization_id', $organizationId)->sharedLock()->firstOrFail();
            $this->views->find($actor, $organizationId, $contractId);
            $baseline = $this->compatibility->snapshot($contract);

            return ['contract_id' => $contractId, 'fingerprint' => $this->compatibility->fingerprint($baseline),
                'already_adopted' => DB::table('contract_builder_instances')->where('contract_id', $contractId)->exists(),
                'current_conditions' => $baseline['contract']];
        });
    }
}
