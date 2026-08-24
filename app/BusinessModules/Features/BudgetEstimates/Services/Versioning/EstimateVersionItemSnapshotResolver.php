<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Models\EstimateItem;
use App\Models\EstimateVersion;
use DomainException;

use function trans_message;

final class EstimateVersionItemSnapshotResolver
{
    public function resolve(EstimateVersion $version, EstimateItem $item): array
    {
        $snapshot = $version->snapshot ?? [];
        $stableKey = (string) ($item->stable_key ?? '');

        foreach ($snapshot['sections'] ?? [] as $section) {
            $resolved = $this->findInSection($section, $stableKey, (int) $item->id);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $resolved = $this->findInItems($snapshot['unsectioned_items'] ?? [], $stableKey, (int) $item->id);
        if ($resolved !== null) {
            return $resolved;
        }

        throw new DomainException(trans_message('estimate.version_item_not_found'));
    }

    private function findInSection(array $section, string $stableKey, int $sourceId): ?array
    {
        $resolved = $this->findInItems($section['items'] ?? [], $stableKey, $sourceId);
        if ($resolved !== null) {
            return $resolved;
        }

        foreach ($section['children'] ?? [] as $child) {
            $resolved = $this->findInSection($child, $stableKey, $sourceId);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function findInItems(array $items, string $stableKey, int $sourceId): ?array
    {
        foreach ($items as $item) {
            if (($stableKey !== '' && ($item['stable_key'] ?? null) === $stableKey)
                || (int) ($item['id'] ?? 0) === $sourceId) {
                return $item;
            }

            $resolved = $this->findInItems($item['children'] ?? [], $stableKey, $sourceId);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }
}
