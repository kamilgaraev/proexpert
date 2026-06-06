<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentTemplate;
use App\BusinessModules\Features\DesignManagement\Models\DesignNormativeSource;
use App\BusinessModules\Features\DesignManagement\Support\DesignNormativeCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class DesignNormativeCatalogService
{
    public function ensureCatalog(): void
    {
        DB::transaction(function (): void {
            $sourceIds = [];

            foreach (DesignNormativeCatalog::sources() as $source) {
                $model = DesignNormativeSource::query()->updateOrCreate(
                    ['code' => $source['code']],
                    [
                        'title' => $source['title'],
                        'version' => $source['version'] ?? null,
                        'effective_from' => $source['effective_from'] ?? null,
                        'effective_to' => $source['effective_to'] ?? null,
                        'source_url' => $source['source_url'] ?? null,
                        'status' => $source['status'] ?? 'active',
                        'metadata' => $source['metadata'] ?? [],
                    ]
                );
                $sourceIds[(string) $model->code] = (int) $model->id;
            }

            foreach (DesignNormativeCatalog::templates() as $template) {
                DesignDocumentTemplate::query()->updateOrCreate(
                    [
                        'profile_code' => $template['profile_code'],
                        'project_stage' => $template['project_stage'],
                        'object_type' => $template['object_type'],
                        'section_code' => $template['section_code'],
                        'document_code' => $template['document_code'],
                    ],
                    [
                        'normative_source_id' => $sourceIds[(string) $template['source_code']] ?? null,
                        'section_title' => $template['section_title'],
                        'document_title' => $template['document_title'],
                        'artifact_type' => $template['artifact_type'],
                        'required' => $template['required'],
                        'sort_order' => $template['sort_order'],
                        'allowed_formats' => $template['allowed_formats'],
                        'sheet_registry_required' => $template['sheet_registry_required'],
                        'normative_reference' => $template['normative_reference'],
                        'metadata' => $template['metadata'],
                    ]
                );
            }
        });
    }

    public function sources(): Collection
    {
        $this->ensureCatalog();

        return DesignNormativeSource::query()
            ->orderBy('effective_from')
            ->orderBy('code')
            ->get();
    }

    public function templates(string $profileCode, string $projectStage, ?string $objectType): Collection
    {
        $this->ensureCatalog();

        return DesignDocumentTemplate::query()
            ->with('normativeSource')
            ->forProfile($profileCode, $projectStage, $objectType)
            ->orderBy('sort_order')
            ->orderBy('section_code')
            ->orderBy('document_code')
            ->get();
    }

    public function profileCode(string $projectStage, string $objectType): string
    {
        return DesignNormativeCatalog::profileFor($projectStage, $objectType);
    }
}
