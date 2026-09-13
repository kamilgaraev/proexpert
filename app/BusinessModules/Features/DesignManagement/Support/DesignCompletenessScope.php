<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use Illuminate\Support\Collection;

final class DesignCompletenessScope
{
    private ?array $items;

    public function __construct(private readonly DesignPackage $package)
    {
        if ($package->composition_revision_id === null) {
            $this->items = null;

            return;
        }

        $revision = DesignCompositionRevision::query()
            ->with('exclusions')
            ->whereKey($package->composition_revision_id)
            ->where('package_id', $package->id)
            ->where('organization_id', $package->organization_id)
            ->where('project_id', $package->project_id)
            ->where('status', 'approved')
            ->first();
        $composition = $revision?->composition ?? [];
        $items = $composition['sections'] ?? $composition['document_groups'] ?? $composition['items'] ?? [];
        $excluded = $revision?->exclusions->pluck('item_key')->all() ?? [];
        $this->items = array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)
            && ! in_array((string) ($item['code'] ?? ''), $excluded, true)));
    }

    public function selectedItems(): ?array
    {
        return $this->items;
    }

    public function sections(): array
    {
        $selected = $this->items === null ? null : collect($this->items)->keyBy('code');
        $result = [];

        foreach ($this->package->sections ?? [] as $section) {
            if (! $section instanceof DesignPackageSection || ($selected !== null && ! $selected->has($section->code))) {
                continue;
            }

            $documents = $selected === null
                ? ($section->metadata['documents'] ?? [])
                : ($selected->get($section->code)['documents'] ?? []);
            $result[] = ['section' => $section, 'documents' => collect($documents)->keyBy('document_code')];
        }

        return $result;
    }

    public function includesArtifact(DesignArtifact $artifact, Collection $documents): bool
    {
        return $this->items === null || $documents->isEmpty() || $documents->has($artifact->document_code);
    }

    public function artifacts(): Collection
    {
        if ($this->items === null) {
            return $this->package->artifacts ?? collect();
        }

        $artifacts = collect();
        foreach ($this->sections() as $entry) {
            foreach ($entry['section']->artifacts as $artifact) {
                if ($this->includesArtifact($artifact, $entry['documents'])) {
                    $artifacts->put($artifact->id, $artifact);
                }
            }
        }

        return $artifacts;
    }
}
