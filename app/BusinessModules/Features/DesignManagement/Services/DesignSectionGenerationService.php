<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDocumentSectionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentTemplate;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DesignSectionGenerationService
{
    public function __construct(
        private readonly DesignNormativeCatalogService $catalogService,
    ) {
    }

    public function generateForPackage(DesignPackage $package): Collection
    {
        $projectStage = $this->value($package->project_stage);
        $objectType = $this->value($package->object_type);
        $profileCode = (string) ($package->normative_profile_code ?: $this->catalogService->profileCode($projectStage, $objectType));
        $templates = $this->catalogService->templates($profileCode, $projectStage, $objectType);

        if ($templates->isEmpty()) {
            $profileCode = $this->catalogService->profileCode($projectStage, $objectType);
            $templates = $this->catalogService->templates($profileCode, $projectStage, $objectType);
        }

        return DB::transaction(function () use ($package, $templates, $profileCode): Collection {
            $package->update(['normative_profile_code' => $profileCode]);

            $sections = $templates
                ->groupBy('section_code')
                ->map(function (Collection $sectionTemplates): DesignPackageSection {
                    /** @var DesignDocumentTemplate $first */
                    $first = $sectionTemplates->first();
                    $documents = $sectionTemplates
                        ->map(static fn (DesignDocumentTemplate $template): array => [
                            'template_id' => $template->id,
                            'document_code' => $template->document_code,
                            'document_title' => $template->document_title,
                            'artifact_type' => $template->artifact_type instanceof BackedEnum ? $template->artifact_type->value : $template->artifact_type,
                            'required' => (bool) $template->required,
                            'allowed_formats' => $template->allowed_formats ?? [],
                            'sheet_registry_required' => (bool) $template->sheet_registry_required,
                            'normative_reference' => $template->normative_reference,
                        ])
                        ->values()
                        ->all();

                    return new DesignPackageSection([
                        'template_id' => $first->id,
                        'code' => $first->section_code,
                        'title' => $first->section_title,
                        'project_stage' => $this->value($first->project_stage),
                        'object_type' => $this->value($first->object_type),
                        'required' => $sectionTemplates->contains(static fn (DesignDocumentTemplate $template): bool => (bool) $template->required),
                        'sort_order' => (int) $first->sort_order,
                        'normative_reference' => $first->normative_reference,
                        'metadata' => [
                            'documents' => $documents,
                        ],
                    ]);
                })
                ->values();

            foreach ($sections as $sectionPayload) {
                $existing = DesignPackageSection::query()
                    ->where('package_id', $package->id)
                    ->where('code', $sectionPayload->code)
                    ->first();

                $status = $existing instanceof DesignPackageSection
                    ? $existing->status
                    : DesignDocumentSectionStatusEnum::NOT_STARTED;

                DesignPackageSection::query()->updateOrCreate(
                    [
                        'package_id' => $package->id,
                        'code' => $sectionPayload->code,
                    ],
                    [
                        'organization_id' => $package->organization_id,
                        'project_id' => $package->project_id,
                        'template_id' => $sectionPayload->template_id,
                        'title' => $sectionPayload->title,
                        'project_stage' => $this->value($sectionPayload->project_stage),
                        'object_type' => $this->value($sectionPayload->object_type),
                        'status' => $this->value($status),
                        'required' => (bool) $sectionPayload->required,
                        'sort_order' => (int) $sectionPayload->sort_order,
                        'normative_reference' => $sectionPayload->normative_reference,
                        'metadata' => $sectionPayload->metadata,
                    ]
                );
            }

            return $this->sectionsForPackage($package);
        });
    }

    public function sectionsForPackage(DesignPackage $package): Collection
    {
        return DesignPackageSection::query()
            ->where('package_id', $package->id)
            ->with([
                'artifacts.currentVersion.sheets',
                'artifacts.versions.sheets',
                'reviewComments',
            ])
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
