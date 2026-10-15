<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Services;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalExport;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class CommercialProposalAmountAccess
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function canView(int $organizationId, ?User $actor = null): bool
    {
        $actor ??= Auth::user();

        return $actor instanceof User
            && $this->isCurrentMember($actor, $organizationId)
            && $this->authorization->canCurrent($actor, 'commercial_proposals.amounts.view', ['organization_id' => $organizationId]);
    }

    public function canDownload(CommercialProposalExport $export, ?User $actor = null): bool
    {
        $actor ??= Auth::user();

        return $export->status === 'ready'
            && is_string($export->storage_path)
            && $export->storage_path !== ''
            && $actor instanceof User
            && $this->isCurrentMember($actor, (int) $export->organization_id)
            && ($export->amounts_visible === false
                || $this->authorization->canCurrent($actor, 'commercial_proposals.amounts.view', ['organization_id' => (int) $export->organization_id]));
    }

    private function isCurrentMember(User $actor, int $organizationId): bool
    {
        return $organizationId > 0
            && (int) $actor->current_organization_id === $organizationId
            && $actor->organizations()->useWritePdo()->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists();
    }
}
