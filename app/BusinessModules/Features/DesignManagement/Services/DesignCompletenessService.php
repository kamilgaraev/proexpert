<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignCompletenessStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompletenessCheck;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRule;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessRuleResult;
use App\BusinessModules\Features\DesignManagement\Support\Rules\AllowedFileFormatRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\OpenBlockingCommentsRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredCurrentArtifactRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RequiredSectionRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\RevisionSequenceRule;
use App\BusinessModules\Features\DesignManagement\Support\Rules\SheetRegistryRule;
use BackedEnum;
use App\Models\User;
use App\BusinessModules\Features\DesignManagement\Enums\DesignDocumentSectionStatusEnum;
use Illuminate\Support\Facades\DB;

final class DesignCompletenessService
{
    /**
     * @var DesignCompletenessRule[]
     */
    private array $rules;

    public function __construct()
    {
        $this->rules = [
            new RequiredSectionRule,
            new RequiredCurrentArtifactRule,
            new AllowedFileFormatRule,
            new RevisionSequenceRule,
            new SheetRegistryRule,
            new OpenBlockingCommentsRule,
        ];
    }

    public function run(DesignPackage $package, int $userId): DesignCompletenessCheck
    {
        app(DesignAccessService::class)->assertPackage($package, User::query()->findOrFail($userId), 'design-management.norm_control.run');
        return $this->calculate($package, $userId);
    }

    public function runForWorkflow(DesignPackage $package, int $userId, string $action): DesignCompletenessCheck
    {
        $permission = \App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow::permissionForAction($action);
        if ($permission === null || ! in_array($action, ['submit_norm_control', 'submit_customer_review', 'approve', 'issue'], true)) {
            throw new \DomainException(trans_message('design_management.errors.workflow_action_not_available'));
        }
        $actor = User::query()->findOrFail($userId);
        app(DesignAccessService::class)->assertPackage($package, $actor, $permission);
        return DB::transaction(function () use ($package, $actor, $permission, $action, $userId): DesignCompletenessCheck {
            $locked = DesignPackage::query()->whereKey($package->id)->where('organization_id', $package->organization_id)->where('project_id', $package->project_id)->lockForUpdate()->firstOrFail();
            app(DesignAccessService::class)->assertPackage($locked, $actor, $permission);
            if (\App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow::nextStatus($locked, $action) === null) {
                throw new \DomainException(trans_message('design_management.errors.workflow_action_not_available'));
            }
            return $this->calculate($locked, $userId);
        });
    }

    private function calculate(DesignPackage $package, int $userId): DesignCompletenessCheck
    {
        return DB::transaction(function () use ($package, $userId): DesignCompletenessCheck {
            $package = DesignPackage::forOrganization((int) $package->organization_id)
                ->whereKey($package->id)
                ->with($this->relations())
                ->lockForUpdate()
                ->firstOrFail();

            $results = [];
            $revision = $this->approvedCompositionRevision($package);

            if ($package->composition_revision_id !== null && $revision === null) {
                $results[] = ['rule' => 'approved_composition_revision', 'status' => DesignCompletenessStatusEnum::BLOCKED->value, 'message' => trans_message('design_composition.errors.approved_revision_required')];
            }

            foreach ($this->rules as $rule) {
                foreach ($rule->check($package) as $result) {
                    if ($result instanceof DesignCompletenessRuleResult) {
                        $results[] = $result->toArray();
                    }
                }
            }

            $blockingCount = count(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === DesignCompletenessStatusEnum::BLOCKED->value
            ));
            $warningCount = count(array_filter(
                $results,
                static fn (array $result): bool => ($result['status'] ?? null) === DesignCompletenessStatusEnum::WARNING->value
            ));
            $status = $blockingCount > 0
                ? DesignCompletenessStatusEnum::BLOCKED
                : ($warningCount > 0 ? DesignCompletenessStatusEnum::WARNING : DesignCompletenessStatusEnum::READY);

            $check = DesignCompletenessCheck::query()->create([
                'organization_id' => $package->organization_id,
                'project_id' => $package->project_id,
                'package_id' => $package->id,
                'created_by' => $userId,
                'status' => $status,
                'profile_code' => $package->normative_profile_code,
                'project_stage' => $this->value($package->project_stage),
                'object_type' => $this->value($package->object_type),
                'checked_at' => now(),
                'blocking_count' => $blockingCount,
                'warning_count' => $warningCount,
                'summary' => [
                    'sections_count' => $package->sections->count(),
                    'artifacts_count' => $package->artifacts->count(),
                    'current_documents_count' => $package->artifacts->filter(static fn ($artifact): bool => $artifact->currentVersion !== null)->count(),
                ],
                'results' => $results,
                'metadata' => [
                    'rule_count' => count($this->rules),
                    'composition_revision_id' => $revision?->id,
                    'composition_fingerprint' => $revision?->fingerprint,
                    'completeness_invalidated_at' => $this->packageInvalidationStamp($package),
                    'input_fingerprint' => $this->inputFingerprint($package),
                ],
            ]);
            foreach ($package->sections as $section) {
                if (($section->metadata['composition_active'] ?? true) === false) {
                    continue;
                }
                $sectionResults = array_values(array_filter($results, static fn (array $result): bool => ($result['metadata']['section_code'] ?? null) === $section->code || (($result['target_type'] ?? null) === 'section' && (int) ($result['target_id'] ?? 0) === (int) $section->id) || (($result['target_type'] ?? null) === 'artifact' && $section->artifacts->contains('id', (int) ($result['target_id'] ?? 0))) || (($result['target_type'] ?? null) === 'version' && $section->artifacts->contains(static fn (DesignArtifact $artifact): bool => (int) ($artifact->currentVersion?->id ?? 0) === (int) ($result['target_id'] ?? 0))) || in_array($result['target_type'] ?? null, ['quality_defect', 'review_comment'], true) || (($result['target_type'] ?? null) === 'package' && ! isset($result['metadata']['section_code']))));
                $blocked = collect($sectionResults)->contains(static fn (array $result): bool => ($result['status'] ?? null) === 'blocked');
                $metadata = $section->metadata ?? [];
                $metadata['readiness_status'] = $blocked ? 'blocked' : (collect($sectionResults)->contains(static fn (array $result): bool => ($result['status'] ?? null) === 'warning') ? 'warning' : 'ready');
                $metadata['readiness_results'] = $sectionResults;
                $metadata['readiness_check_id'] = $check->id;
                $sectionStatus = match ($package->status->value) {
                    'issued', 'archived' => DesignDocumentSectionStatusEnum::ISSUED,
                    'approved', 'under_customer_review' => DesignDocumentSectionStatusEnum::CHECKED,
                    'returned' => DesignDocumentSectionStatusEnum::RETURNED,
                    default => $blocked ? DesignDocumentSectionStatusEnum::IN_WORK : DesignDocumentSectionStatusEnum::READY_FOR_CHECK,
                };
                $section->update(['status' => $sectionStatus, 'metadata' => $metadata]);
            }
            return $check;
        });
    }

    public function invalidateLockedInputs(DesignPackage $package, ?int $userId = null): void
    {
        DB::transaction(function () use ($package, $userId): void {
            $locked = DesignPackage::query()->whereKey($package->id)->where('organization_id', $package->organization_id)
                ->where('project_id', $package->project_id)->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $stamp = now();
            if (is_string($metadata['completeness_invalidated_at'] ?? null)) {
                try {
                    $previous = \Illuminate\Support\Carbon::parse($metadata['completeness_invalidated_at']);
                    if ($previous->greaterThanOrEqualTo($stamp)) {
                        $stamp = $previous->addMicrosecond();
                    }
                } catch (\Carbon\Exceptions\InvalidFormatException) {
                    $stamp = now();
                }
            }
            $metadata['completeness_invalidated_at'] = $stamp->toISOString();
            $locked->update(['metadata' => $metadata] + ($userId === null ? [] : ['updated_by' => $userId]));
        });
    }

    public function isFreshForPackage(DesignPackage $package, ?DesignCompletenessCheck $check): bool
    {
        if ($check === null) {
            return false;
        }
        $revision = $this->approvedCompositionRevision($package);
        if ($package->composition_revision_id !== null && $revision === null) {
            return false;
        }
        $metadata = is_array($check->metadata) ? $check->metadata : [];

        return ($metadata['composition_revision_id'] ?? null) === $revision?->id
            && ($metadata['composition_fingerprint'] ?? null) === $revision?->fingerprint
            && ($metadata['completeness_invalidated_at'] ?? null) === $this->packageInvalidationStamp($package)
            && ($metadata['input_fingerprint'] ?? null) === $this->inputFingerprint($package);
    }

    private function approvedCompositionRevision(DesignPackage $package): ?DesignCompositionRevision
    {
        if ($package->composition_revision_id === null) {
            return null;
        }

        return DesignCompositionRevision::query()->whereKey($package->composition_revision_id)->where('package_id', $package->id)->where('organization_id', $package->organization_id)->where('project_id', $package->project_id)->where('status', 'approved')->first();
    }

    private function packageInvalidationStamp(DesignPackage $package): ?string
    {
        $metadata = is_array($package->metadata) ? $package->metadata : [];

        return isset($metadata['completeness_invalidated_at']) ? (string) $metadata['completeness_invalidated_at'] : null;
    }

    private function inputFingerprint(DesignPackage $package): string
    {
        $sections = $package->sections()->orderBy('id')->get()->map(static fn ($item): array => ['id' => $item->id, 'code' => $item->code, 'required' => $item->required, 'metadata' => array_diff_key($item->metadata ?? [], array_flip(['readiness_status', 'readiness_results', 'readiness_check_id']))])->all();
        $artifacts = DesignArtifact::query()->where('package_id', $package->id)->orderBy('id')->get(['id', 'section_id', 'document_code', 'title', 'requires_sheet_registry', 'status', 'metadata', 'updated_at'])->map(static fn (DesignArtifact $item): array => $item->only(['id', 'section_id', 'document_code', 'title', 'requires_sheet_registry', 'status', 'metadata', 'updated_at']))->all();
        $versions = DesignArtifactVersion::query()->whereHas('artifact', static fn ($query) => $query->where('package_id', $package->id))->orderBy('id')->get(['id', 'artifact_id', 'status', 'is_current', 'revision', 'revision_label', 'source_format', 'file_format', 'page_count', 'sheet_count', 'metadata', 'updated_at'])->map(static fn (DesignArtifactVersion $item): array => $item->only(['id', 'artifact_id', 'status', 'is_current', 'revision', 'revision_label', 'source_format', 'file_format', 'page_count', 'sheet_count', 'metadata', 'updated_at']))->all();
        $sheets = DesignDocumentSheet::query()->where('package_id', $package->id)->orderBy('id')->get(['id', 'version_id', 'status', 'revision', 'updated_at'])->map(static fn (DesignDocumentSheet $item): array => $item->only(['id', 'version_id', 'status', 'revision', 'updated_at']))->all();
        $comments = DesignReviewComment::query()->where('package_id', $package->id)->orderBy('id')->get(['id', 'status', 'severity', 'updated_at'])->map(static fn (DesignReviewComment $item): array => $item->only(['id', 'status', 'severity', 'updated_at']))->all();
        $issues = $this->blockingIssues($package);
        $exclusions = $package->composition_revision_id === null ? [] : DesignCompositionRevision::query()->whereKey($package->composition_revision_id)->with('exclusions')->first()?->exclusions->map(static fn ($item): array => ['id' => $item->id, 'item_key' => $item->item_key, 'reason' => $item->reason, 'created_at' => $item->created_at?->toISOString()])->all();
        return hash('sha256', json_encode(['sections' => $sections, 'artifacts' => $artifacts, 'versions' => $versions, 'sheets' => $sheets, 'comments' => $comments, 'issues' => $issues, 'exclusions' => $exclusions], JSON_THROW_ON_ERROR));
    }

    private function blockingIssues(DesignPackage $package): array
    {
        return DesignPackageBlockingIssueQuery::forPackage($package)->orderBy('id')->get(['id', 'status', 'metadata', 'updated_at'])->map(static fn ($item): array => ['id' => $item->id, 'status' => $item->status, 'metadata' => $item->metadata, 'updated_at' => $item->updated_at?->toISOString()])->all();
    }

    public function latestForPackage(DesignPackage $package): ?DesignCompletenessCheck
    {
        return DesignCompletenessCheck::query()
            ->where('package_id', $package->id)
            ->latest('checked_at')
            ->first();
    }

    public function packageWithRelations(DesignPackage $package): DesignPackage
    {
        return $package->fresh($this->relations());
    }

    private function relations(): array
    {
        return [
            'project:id,name,organization_id',
            'sections.artifacts.currentVersion.sheets',
            'sections.artifacts.versions.sheets',
            'sections.reviewComments',
            'artifacts.currentVersion.sheets',
            'artifacts.versions.sheets',
            'reviewComments',
            'latestCompletenessCheck',
        ];
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
