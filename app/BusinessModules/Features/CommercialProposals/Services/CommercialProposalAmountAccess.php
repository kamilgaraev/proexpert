<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CommercialProposalAmountAccess
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function canView(int $organizationId, ?User $actor = null): bool
    {
        return $this->snapshot($organizationId, $actor)->canViewAmounts;
    }

    public function snapshot(int $organizationId, ?User $actor = null): CommercialProposalAmountSnapshot
    {
        $actor ??= Auth::user();
        $isMember = $actor instanceof User && $this->isCurrentMember($actor, $organizationId);

        return new CommercialProposalAmountSnapshot(
            $organizationId,
            $isMember,
            $actor instanceof User && $isMember
                && $this->authorization->canCurrent($actor, 'commercial_proposals.amounts.view', ['organization_id' => $organizationId]),
        );
    }

    public function forRequest(Request $request, int $organizationId): CommercialProposalAmountSnapshot
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || (int) $actor->current_organization_id !== $organizationId
            || (int) $request->attributes->get('current_organization_id') !== $organizationId) {
            return new CommercialProposalAmountSnapshot($organizationId, false, false);
        }

        $snapshots = $request->attributes->get(self::class, []);
        $key = $actor->id.':'.$organizationId;
        if (! isset($snapshots[$key])) {
            $snapshots[$key] = $this->snapshot($organizationId, $actor);
            $request->attributes->set(self::class, $snapshots);
        }

        return $snapshots[$key];
    }

    private function isCurrentMember(User $actor, int $organizationId): bool
    {
        return $organizationId > 0
            && (int) $actor->current_organization_id === $organizationId
            && $actor->organizations()->useWritePdo()->where('organizations.id', $organizationId)
                ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists();
    }
}
