<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSiteRevision;
use App\Models\User;

class SiteRevisionService
{
    public function createPublishedRevision(HoldingSite $site, array $payload, User $user, ?string $label = null): HoldingSiteRevision
    {
        return HoldingSiteRevision::create([
            'holding_site_id' => $site->id,
            'kind' => 'published',
            'label' => $label ?? sprintf('Published %s', now()->format('Y-m-d H:i')),
            'payload' => $payload,
            'created_by_user_id' => $user->id,
        ]);
    }

    public function listForSite(HoldingSite $site): array
    {
        return $site->revisions()
            ->with('creator:id,name,email')
            ->limit(25)
            ->get()
            ->map(static fn (HoldingSiteRevision $revision) => [
                'id' => $revision->id,
                'kind' => $revision->kind,
                'label' => $revision->label,
                'created_at' => optional($revision->created_at)->toISOString(),
                'creator' => [
                    'id' => $revision->creator?->id,
                    'name' => $revision->creator?->name,
                    'email' => $revision->creator?->email,
                ],
            ])
            ->values()
            ->all();
    }

    public function rollback(HoldingSite $site, HoldingSiteRevision $revision, User $user): HoldingSite
    {
        $site->update([
            'status' => 'published',
            'published_payload' => $revision->payload,
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ]);

        $site->clearCache();

        return $site->fresh();
    }
}
