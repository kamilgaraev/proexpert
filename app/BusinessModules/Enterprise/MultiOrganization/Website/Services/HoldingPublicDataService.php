<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Services;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\Models\OrganizationGroup;

final class HoldingPublicDataService
{
    public function forGroup(OrganizationGroup $group): ?array
    {
        $site = HoldingSite::query()
            ->where('organization_group_id', $group->id)
            ->whereHas('organizationGroup', static fn ($query) => $query->where('status', 'active'))
            ->where('status', 'published')
            ->where('is_active', true)
            ->first();
        if (!$site || !$site->isPublished()) {
            return null;
        }
        $organization = self::organization($site->getPublishedPayload()['organization'] ?? null);
        if (empty($organization['holding']['name']) || empty($organization['organization']['name'])) {
            return null;
        }

        return [
            'holding' => $organization['holding'],
            'parent_organization' => $organization['organization'],
            'stats' => null,
        ];
    }

    public static function organization(mixed $value): array
    {
        $payload = is_array($value) ? $value : [];
        $public = [];
        foreach ([
            'holding' => ['id', 'name', 'slug', 'description'],
            'organization' => ['id', 'name', 'description', 'phone', 'email', 'address', 'city'],
        ] as $key => $fields) {
            $source = is_array($payload[$key] ?? null) ? $payload[$key] : [];
            $public[$key] = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $source) && (is_scalar($source[$field]) || $source[$field] === null)) {
                    $public[$key][$field] = $source[$field];
                }
            }
        }

        return $public;
    }
}
