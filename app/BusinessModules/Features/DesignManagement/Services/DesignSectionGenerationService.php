<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDocumentSectionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentTemplate;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use BackedEnum;
use DomainException;
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

    public function storeCustomSectionDocument(DesignPackage $package, array $payload): DesignPackageSection
    {
        if (!DesignPackageWorkflow::canChangeDocuments($package)) {
            throw new DomainException(trans_message('design_management.errors.package_locked_for_document_changes'));
        }

        return DB::transaction(function () use ($package, $payload): DesignPackageSection {
            $sectionCode = $this->normalizeCode((string) $payload['section_code']);
            $documentCode = $this->normalizeCode((string) $payload['document_code']);
            $document = $this->customDocumentMetadata($payload, $documentCode);
            $section = DesignPackageSection::query()
                ->where('package_id', $package->id)
                ->where('code', $sectionCode)
                ->lockForUpdate()
                ->first();

            if ($section instanceof DesignPackageSection) {
                $metadata = $this->appendDocumentMetadata($section->metadata, $document, $documentCode);
                $section->update(['metadata' => $metadata]);

                return $this->freshSection($section);
            }

            $section = DesignPackageSection::query()->create([
                'organization_id' => $package->organization_id,
                'project_id' => $package->project_id,
                'package_id' => $package->id,
                'template_id' => null,
                'code' => $sectionCode,
                'title' => trim((string) $payload['section_title']),
                'project_stage' => $this->value($package->project_stage),
                'object_type' => $this->value($package->object_type),
                'status' => DesignDocumentSectionStatusEnum::NOT_STARTED->value,
                'required' => (bool) ($payload['required'] ?? true),
                'sort_order' => (int) ($payload['sort_order'] ?? $this->nextSortOrder($package)),
                'normative_reference' => $this->nullableText($payload['normative_reference'] ?? null),
                'metadata' => [
                    'documents' => [$document],
                ],
            ]);

            return $this->freshSection($section);
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

    private function appendDocumentMetadata(mixed $metadata, array $document, string $documentCode): array
    {
        $metadata = is_array($metadata) ? $metadata : [];
        $documents = is_array($metadata['documents'] ?? null) ? array_values($metadata['documents']) : [];

        foreach ($documents as $existingDocument) {
            if (!is_array($existingDocument)) {
                continue;
            }

            if ($this->normalizeCode((string) ($existingDocument['document_code'] ?? '')) === $documentCode) {
                throw new DomainException(trans_message('design_management.errors.custom_document_duplicate'));
            }
        }

        $documents[] = $document;
        $metadata['documents'] = $documents;

        return $metadata;
    }

    private function customDocumentMetadata(array $payload, string $documentCode): array
    {
        return [
            'template_id' => null,
            'document_code' => $documentCode,
            'document_title' => trim((string) $payload['document_title']),
            'artifact_type' => (string) ($payload['artifact_type'] ?? 'text_document'),
            'required' => (bool) ($payload['required'] ?? true),
            'allowed_formats' => array_values($payload['allowed_formats'] ?? ['pdf']),
            'sheet_registry_required' => (bool) ($payload['sheet_registry_required'] ?? false),
            'normative_reference' => $this->nullableText($payload['normative_reference'] ?? null),
            'source' => 'custom',
        ];
    }

    private function freshSection(DesignPackageSection $section): DesignPackageSection
    {
        $fresh = $section->fresh([
            'artifacts.currentVersion.sheets',
            'artifacts.versions.sheets',
            'reviewComments',
        ]);

        if ($fresh instanceof DesignPackageSection) {
            return $fresh;
        }

        return $section->load([
            'artifacts.currentVersion.sheets',
            'artifacts.versions.sheets',
            'reviewComments',
        ]);
    }

    private function nextSortOrder(DesignPackage $package): int
    {
        $maxSortOrder = DesignPackageSection::query()
            ->where('package_id', $package->id)
            ->max('sort_order');

        return ((int) ($maxSortOrder ?? 0)) + 10;
    }

    private function normalizeCode(string $code): string
    {
        $normalized = (string) preg_replace('/\s+/u', '_', trim($code));

        return mb_strtoupper($normalized, 'UTF-8');
    }

    private function nullableText(mixed $value): ?string
    {
        return $value !== null ? trim((string) $value) : null;
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
