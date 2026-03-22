<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSiteCollaborator;
use App\Models\User;

class SiteCollaboratorService
{
    public function listForSite(HoldingSite $site): array
    {
        return $site->collaborators()
            ->with(['user:id,name,email', 'invitedBy:id,name,email'])
            ->orderByRaw("case when role = 'owner' then 0 when role = 'publisher' then 1 when role = 'editor' then 2 else 3 end")
            ->orderBy('created_at')
            ->get()
            ->map(static fn (HoldingSiteCollaborator $collaborator) => [
                'id' => $collaborator->id,
                'role' => $collaborator->role,
                'user' => [
                    'id' => $collaborator->user?->id,
                    'name' => $collaborator->user?->name,
                    'email' => $collaborator->user?->email,
                ],
                'invited_by' => [
                    'id' => $collaborator->invitedBy?->id,
                    'name' => $collaborator->invitedBy?->name,
                    'email' => $collaborator->invitedBy?->email,
                ],
                'created_at' => optional($collaborator->created_at)->toISOString(),
            ])
            ->values()
            ->all();
    }

    public function addCollaborator(HoldingSite $site, User $targetUser, string $role, User $inviter): HoldingSiteCollaborator
    {
        return HoldingSiteCollaborator::updateOrCreate(
            [
                'holding_site_id' => $site->id,
                'user_id' => $targetUser->id,
            ],
            [
                'role' => $role,
                'invited_by_user_id' => $inviter->id,
            ]
        );
    }

    public function updateCollaborator(HoldingSiteCollaborator $collaborator, string $role): HoldingSiteCollaborator
    {
        $collaborator->update(['role' => $role]);

        return $collaborator->fresh(['user', 'invitedBy']);
    }

    public function removeCollaborator(HoldingSiteCollaborator $collaborator): void
    {
        $collaborator->delete();
    }
}
