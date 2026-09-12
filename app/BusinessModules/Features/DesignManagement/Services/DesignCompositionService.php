<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionExclusion;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class DesignCompositionService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function preview(int $organizationId, User $actor, array $payload): array
    {
        $this->authorize($actor, 'design-management.composition.edit', $organizationId, (int) $payload['project_id']);

        return $this->normalized($payload['project_stage'], $payload['composition']);
    }

    public function createRevision(DesignPackage $package, User $actor, array $payload): DesignCompositionRevision
    {
        $this->authorize($actor, 'design-management.composition.edit', (int) $package->organization_id, (int) $package->project_id);
        $stage = $package->project_stage instanceof \BackedEnum ? $package->project_stage->value : (string) $package->project_stage;
        $composition = $this->normalized($stage, $payload['composition']);

        return DB::transaction(function () use ($package, $actor, $composition, $payload): DesignCompositionRevision {
            $locked = DesignPackage::query()->whereKey($package->id)->where('organization_id', $package->organization_id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status->value, ['issued', 'archived'], true)) {
                throw new DomainException(trans_message('design_composition.errors.issued_package_locked'));
            }
            $latest = (int) DesignCompositionRevision::query()->where('package_id', $locked->id)->max('revision_number');
            if ((int) ($payload['expected_revision'] ?? 0) !== $latest) {
                throw new DomainException(trans_message('design_composition.errors.revision_conflict'));
            }
            $number = $latest + 1;
            $revision = DesignCompositionRevision::query()->create(['organization_id' => $locked->organization_id, 'project_id' => $locked->project_id, 'package_id' => $locked->id, 'revision_number' => $number, 'status' => 'draft', 'composition' => $composition, 'fingerprint' => hash('sha256', json_encode($composition, JSON_THROW_ON_ERROR)), 'created_by' => $actor->id]);
            $this->materialize($locked, $composition, $actor);
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['completeness_invalidated_at'] = now()->toISOString();
            $locked->update(['composition_status' => 'draft', 'composition_revision_id' => $revision->id, 'updated_by' => $actor->id, 'metadata' => $metadata]);

            return $revision->fresh(['author', 'approvedBy', 'exclusions.author']);
        });
    }

    public function approve(DesignCompositionRevision $revision, User $actor): DesignCompositionRevision
    {
        $this->authorize($actor, 'design-management.composition.approve', (int) $revision->organization_id, (int) $revision->project_id);

        return DB::transaction(function () use ($revision, $actor): DesignCompositionRevision {
            $package = DesignPackage::query()->whereKey($revision->package_id)->lockForUpdate()->firstOrFail();
            $locked = DesignCompositionRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if (in_array($package->status->value, ['issued', 'archived'], true) || (int) $package->composition_revision_id !== (int) $locked->id) {
                throw new DomainException(trans_message('design_composition.errors.issued_package_locked'));
            }
            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'needs_review_reason' => null]);
            DesignPackage::query()->whereKey($locked->package_id)->update(['composition_status' => 'approved', 'composition_revision_id' => $locked->id, 'updated_by' => $actor->id]);

            return $locked->fresh(['author', 'approvedBy', 'exclusions.author']);
        });
    }

    public function needsReview(DesignCompositionRevision $revision, User $actor, string $reason): DesignCompositionRevision
    {
        $this->authorize($actor, 'design-management.composition.edit', (int) $revision->organization_id, (int) $revision->project_id);
        return DB::transaction(function () use ($revision, $actor, $reason): DesignCompositionRevision {
            $package = DesignPackage::query()->whereKey($revision->package_id)->lockForUpdate()->firstOrFail();
            $locked = DesignCompositionRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if (in_array($package->status->value, ['issued', 'archived'], true) || (int) $package->composition_revision_id !== (int) $locked->id) {
                throw new DomainException(trans_message('design_composition.errors.issued_package_locked'));
            }
            $locked->update(['status' => 'needs_review', 'needs_review_reason' => $reason]);
            $package->update(['composition_status' => 'needs_review', 'updated_by' => $actor->id]);

            return $locked->fresh(['author', 'approvedBy', 'exclusions.author']);
        });
    }

    public function exclude(DesignCompositionRevision $revision, User $actor, array $payload): DesignCompositionExclusion
    {
        $this->authorize($actor, 'design-management.composition.edit', (int) $revision->organization_id, (int) $revision->project_id);
        return DB::transaction(function () use ($revision, $actor, $payload): DesignCompositionExclusion {
            $package = DesignPackage::query()->whereKey($revision->package_id)->lockForUpdate()->firstOrFail();
            $locked = DesignCompositionRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') {
                throw new DomainException(trans_message('design_composition.errors.approved_revision_locked'));
            }
            if (in_array($package->status->value, ['issued', 'archived'], true) || (int) $package->composition_revision_id !== (int) $locked->id) {
                throw new DomainException(trans_message('design_composition.errors.issued_package_locked'));
            }
            if (! in_array((string) $payload['item_key'], $this->compositionKeys($locked->composition), true)) {
                throw new DomainException(trans_message('design_composition.errors.exclusion_item_not_found'));
            }

            return DesignCompositionExclusion::query()->create(['organization_id' => $locked->organization_id, 'project_id' => $locked->project_id, 'package_id' => $locked->package_id, 'revision_id' => $locked->id, 'item_key' => $payload['item_key'], 'reason' => $payload['reason'], 'created_by' => $actor->id])->fresh('author');
        });
    }

    public function current(DesignPackage $package, User $actor): ?DesignCompositionRevision
    {
        $this->authorize($actor, 'design-management.view', (int) $package->organization_id, (int) $package->project_id);

        return DesignCompositionRevision::query()->where('package_id', $package->id)->whereKey($package->composition_revision_id)->with(['author', 'approvedBy', 'exclusions.author'])->first();
    }

    private function authorize(User $actor, string $permission, int $organizationId, int $projectId): void
    {
        if (! $this->authorization->can($actor, $permission, ['organization_id' => $organizationId, 'project_id' => $projectId])) {
            throw new DomainException(trans_message('design_composition.errors.forbidden'));
        }
    }

    private function normalized(string $stage, mixed $composition): array
    {
        if (! is_array($composition)) {
            throw new DomainException(trans_message('design_composition.errors.composition_required'));
        }
        $stage = strtolower($stage);
        $result = ['project_stage' => $stage];
        if ($stage === 'pd') {
            $sections = array_values($composition['sections'] ?? []);
            if ($sections === []) {
                throw new DomainException(trans_message('design_composition.errors.pd_sections_required'));
            }
            $this->assertUniqueCodes($sections);
            $result['sections'] = $sections;
        } elseif ($stage === 'rd') {
            $brand = trim((string) ($composition['brand'] ?? ''));
            $groups = array_values($composition['document_groups'] ?? []);
            if ($brand === '' || $groups === []) {
                throw new DomainException(trans_message('design_composition.errors.rd_brand_and_groups_required'));
            } $result['brand'] = $brand;
            $this->assertUniqueCodes($groups);
            $result['document_groups'] = $groups;
        } elseif (in_array($stage, ['survey', 'bim'], true)) {
            $items = array_values($composition['items'] ?? []);
            if ($items === []) {
                throw new DomainException(trans_message('design_composition.errors.explicit_items_required'));
            }
            $this->assertUniqueCodes($items);
            $result['items'] = $items;
        } else {
            throw new DomainException(trans_message('design_composition.errors.stage_not_supported'));
        }

        return $result;
    }

    private function compositionKeys(array $composition): array
    {
        $items = $composition['sections'] ?? $composition['document_groups'] ?? $composition['items'] ?? [];
        return array_values(array_filter(array_map(static fn (mixed $item): ?string => is_array($item) && isset($item['code']) ? (string) $item['code'] : null, $items)));
    }

    private function assertUniqueCodes(array &$items): void
    {
        $codes = [];
        foreach ($items as &$item) {
            if (! is_array($item) || trim((string) ($item['code'] ?? '')) === '') throw new DomainException(trans_message('design_composition.errors.item_code_required'));
            $code = mb_strtoupper(trim((string) $item['code']), 'UTF-8');
            if (isset($codes[$code])) throw new DomainException(trans_message('design_composition.errors.duplicate_item_code'));
            $codes[$code] = true;
            $item['code'] = $code;
            $documents = $item['documents'] ?? [];
            if (! is_array($documents)) {
                throw new DomainException(trans_message('design_composition.errors.composition_required'));
            }
            $documentCodes = [];
            foreach ($documents as &$document) {
                if (! is_array($document) || trim((string) ($document['document_code'] ?? '')) === '') {
                    throw new DomainException(trans_message('design_composition.errors.item_code_required'));
                }
                $documentCode = mb_strtoupper(trim((string) $document['document_code']), 'UTF-8');
                if (isset($documentCodes[$documentCode])) {
                    throw new DomainException(trans_message('design_composition.errors.duplicate_item_code'));
                }
                $documentCodes[$documentCode] = true;
                $document['document_code'] = $documentCode;
            }
            unset($document);
            $item['documents'] = array_values($documents);
        }
    }

    private function materialize(DesignPackage $package, array $composition, User $actor): void
    {
        $items = $composition['sections'] ?? $composition['document_groups'] ?? $composition['items'] ?? [];
        foreach ($items as $index => $item) {
            $code = mb_strtoupper(trim((string) $item['code']), 'UTF-8');
            $section = DesignPackageSection::query()->firstOrNew(['package_id' => $package->id, 'code' => $code]);
            $metadata = is_array($section->metadata) ? $section->metadata : [];
            $metadata['documents'] = $item['documents'];
            $section->fill(['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'title' => (string) ($item['title'] ?? $code), 'project_stage' => $package->project_stage, 'object_type' => $package->object_type, 'required' => (bool) ($item['required'] ?? true), 'sort_order' => ($index + 1) * 10, 'metadata' => $metadata]);
            $section->save();
            foreach ($metadata['documents'] as $document) {
                if (! is_array($document) || trim((string) ($document['document_code'] ?? '')) === '') continue;
                DesignArtifact::query()->firstOrCreate(['package_id' => $package->id, 'section_id' => $section->id, 'document_code' => (string) $document['document_code']], ['organization_id' => $package->organization_id, 'project_id' => $package->project_id, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'artifact_type' => (string) ($document['artifact_type'] ?? 'text_document'), 'title' => (string) ($document['document_title'] ?? $document['document_code']), 'document_title' => (string) ($document['document_title'] ?? $document['document_code']), 'requires_sheet_registry' => (bool) ($document['sheet_registry_required'] ?? false), 'status' => 'active', 'metadata' => ['composition_managed' => true]]);
            }
        }
    }
}
