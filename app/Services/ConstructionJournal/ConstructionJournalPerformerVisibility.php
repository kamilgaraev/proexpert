<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Models\Organization;

final class ConstructionJournalPerformerVisibility
{
    public static function ids(int $organizationId, array $facts): array
    {
        $hierarchy = $facts['hierarchy'];
        $visible = [$organizationId];
        if ($hierarchy['configured']) {
            do {
                $count = count($visible);
                foreach ($hierarchy['participants'] as $participant) {
                    if (in_array($participant['parent_organization_id'], $visible, true)
                        && ! in_array($participant['organization_id'], $visible, true)) {
                        $visible[] = $participant['organization_id'];
                    }
                }
            } while (count($visible) !== $count);
        }

        if (in_array($organizationId, array_column($hierarchy['participants'], 'organization_id'), true)) {
            return array_values(array_intersect($facts['active_organization_ids'], $visible));
        }

        return Organization::query()->whereIn('id', $visible)->where('is_active', true)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
