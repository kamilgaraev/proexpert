<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentApprovedList;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\MeasurementUnit;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentRequirementsService
{
    public function replaceForSet(ExecutiveDocumentSet $set, array $requirements, User $actor, AuthorizationService $authorization, ?int $expectedCompositionRevision = null, ?string $operationKey = null): void
    {
        Validator::make(['requirements' => $requirements, 'operation_key' => $operationKey, 'revision' => $expectedCompositionRevision], [
            'requirements' => ['required', 'array', 'min:1', 'max:500'],
            'operation_key' => ['nullable', 'string', 'min:1', 'max:128'],
            'revision' => ['nullable', 'integer', 'min:0'],
        ])->validate();
        DB::transaction(function () use ($set, $requirements, $actor, $authorization, $expectedCompositionRevision, $operationKey): void {
            $lockedSet = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($set->id);
            $this->assertCanManage($lockedSet, $actor, $authorization);
            $events = DB::table('executive_document_requirement_events')->where('document_set_id', $lockedSet->id);
            $requestHash = hash('sha256', json_encode(\Illuminate\Support\Arr::sortRecursive($requirements), JSON_THROW_ON_ERROR));
            if ($operationKey !== null) {
                $operation = (clone $events)->where('operation_key', $operationKey)->first();
                if ($operation !== null) {
                    if ($operation->request_hash !== $requestHash || (int) $operation->actor_id !== (int) $actor->id) {
                        throw new BusinessLogicException(trans_message('executive_documentation.requirements.operation_conflict'), 409);
                    }

                    return;
                }
            }
            if ($expectedCompositionRevision !== null && (int) (clone $events)->max('id') !== $expectedCompositionRevision) {
                throw new BusinessLogicException(trans_message('executive_documentation.requirements.composition_conflict'), 409);
            }
            if ($lockedSet->status->value !== 'draft') {
                throw ValidationException::withMessages(['requirements' => trans_message('executive_documentation.requirements.frozen')]);
            }
            $previous = ExecutiveDocumentRequirement::query()->where('document_set_id', $set->id)->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get();
            if ($previous->isNotEmpty()) {
                $this->assertCanApprove($lockedSet, $actor, $authorization);
            }
            $existingEvidence = $previous->keyBy(static fn (ExecutiveDocumentRequirement $item): string => $item->requirement_key.'|'.$item->profile_type.'|'.json_encode([
                $item->work_type_id, $item->project_location_id, $item->completed_work_id,
            ], JSON_THROW_ON_ERROR));
            foreach ($previous as $oldRequirement) {
                $before = $oldRequirement->toArray();
                $oldRequirement->forceFill(['superseded_at' => now(), 'revision' => $oldRequirement->revision + 1])->save();
                $this->recordEvent($oldRequirement, $actor, 'superseded', $before);
            }
            $created = null;
            foreach ($requirements as $requirement) {
                $created = $this->createLocked($lockedSet, $requirement, $actor, $authorization);
                $key = $created->requirement_key.'|'.$created->profile_type.'|'.json_encode([
                    $created->work_type_id, $created->project_location_id, $created->completed_work_id,
                ], JSON_THROW_ON_ERROR);
                $prior = $existingEvidence->get($key);
                if ($prior !== null && $prior->evidence !== []) {
                    $before = $created->toArray();
                    $created->forceFill(['evidence' => $prior->evidence, 'revision' => $created->revision + 1])->save();
                    $this->recordEvent($created, $actor, 'evidence_carried_forward', $before);
                }
            }
            if ($created !== null && $operationKey !== null) {
                $this->recordEvent($created, $actor, 'composition_replaced', null, $operationKey, $requestHash);
            }
        });
    }

    public function create(ExecutiveDocumentSet $set, array $requirement, User $actor, AuthorizationService $authorization): ExecutiveDocumentRequirement
    {
        return DB::transaction(function () use ($set, $requirement, $actor, $authorization): ExecutiveDocumentRequirement {
            $lockedSet = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($set->id);
            $this->assertCanManage($lockedSet, $actor, $authorization);
            if ($lockedSet->status->value !== 'draft') {
                throw ValidationException::withMessages(['requirements' => trans_message('executive_documentation.requirements.frozen')]);
            }

            return $this->createLocked($lockedSet, $requirement, $actor, $authorization);
        });
    }

    private function createLocked(ExecutiveDocumentSet $set, array $requirement, User $actor, AuthorizationService $authorization): ExecutiveDocumentRequirement
    {
        $this->assertCanManage($set, $actor, $authorization);
        $profile = (string) ($requirement['profile_type'] ?? '');
        $profileDefinition = app(ExecutiveDocumentProfileRegistry::class)->find($profile);
        if ($profileDefinition === null) {
            throw ValidationException::withMessages(['profile_type' => trans_message('executive_documentation.requirements.unknown_profile')]);
        }
        $conditions = $requirement['conditions'] ?? [];
        if (! is_array($conditions)) {
            throw ValidationException::withMessages(['conditions' => trans_message('executive_requirements.invalid_conditions')]);
        }
        if ($conditions !== []) {
            $this->assertCanApprove($set, $actor, $authorization);
        }
        try {
            $ruleSnapshot = app(ExecutiveDocumentRequirementRuleFactory::class)->snapshot($profileDefinition, $conditions);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['conditions' => trans_message('executive_requirements.invalid_conditions')]);
        }
        $ruleSnapshot['conditions_decided_by'] = $conditions !== [] ? (int) $actor->id : null;
        $ruleSnapshot['conditions_decided_at'] = $conditions !== [] ? now()->toISOString() : null;
        $source = trim((string) ($requirement['source'] ?? ''));
        $revision = trim((string) ($requirement['source_revision'] ?? ''));
        if ($profile === '' || $source === '' || $revision === '') {
            throw ValidationException::withMessages(['requirements' => trans_message('executive_requirements.source_required')]);
        }
        $applicability = (string) ($requirement['applicability'] ?? 'required');
        if (! in_array($applicability, ['required', 'conditional', 'not_applicable'], true)) {
            throw ValidationException::withMessages(['applicability' => trans_message('executive_requirements.invalid_applicability')]);
        }
        if ($applicability === 'not_applicable' && trim((string) ($requirement['not_applicable_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['not_applicable_reason' => trans_message('executive_documentation.requirements.reason_required')]);
        }
        if ($applicability === 'not_applicable') {
            throw ValidationException::withMessages(['applicability' => trans_message('executive_requirements.separate_applicability_decision')]);
        }
        try {
            $coverage = app(ExecutiveDocumentCoverageDeclaration::class)->normalizeScope((array) ($requirement['coverage_scope'] ?? []));
        } catch (\DomainException) {
            throw ValidationException::withMessages(['coverage_scope' => trans_message('executive_documentation.requirements.coverage_invalid')]);
        }
        Validator::make($coverage, [
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'work_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'project_location_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'completed_work_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();
        if (isset($coverage['project_id']) && (int) $coverage['project_id'] !== (int) $set->project_id) {
            throw ValidationException::withMessages(['coverage_scope' => trans_message('executive_documentation.requirements.scope_conflict')]);
        }
        $coverage['project_id'] = (int) $set->project_id;
        foreach (['work_type_id', 'project_location_id', 'completed_work_id'] as $key) {
            if (isset($requirement[$key], $coverage[$key]) && (int) $requirement[$key] !== (int) $coverage[$key]) {
                throw ValidationException::withMessages(['coverage_scope' => trans_message('executive_documentation.requirements.scope_conflict')]);
            }
            $value = $requirement[$key] ?? $coverage[$key] ?? null;
            if ($value !== null) {
                $requirement[$key] = (int) $value;
                $coverage[$key] = (int) $value;
            }
        }
        $this->assertScopedReference($set, $requirement);
        if (array_key_exists('quantity', $coverage) || array_key_exists('measurement_unit_id', $coverage)) {
            try {
                $declared = app(ExecutiveDocumentCoverageDeclaration::class)->normalize([
                    'quantity' => $coverage['quantity'] ?? null,
                    'measurement_unit_id' => $coverage['measurement_unit_id'] ?? null,
                ], is_string($coverage['quantity'] ?? null) ? $coverage['quantity'] : '', (int) ($coverage['measurement_unit_id'] ?? 0));
                if (! MeasurementUnit::query()->whereKey($declared['measurement_unit_id'])->where('organization_id', $set->organization_id)->exists()) {
                    throw new \DomainException;
                }
                $coverage = array_merge($coverage, $declared);
            } catch (\DomainException) {
                throw ValidationException::withMessages(['coverage_scope' => trans_message('executive_documentation.requirements.coverage_invalid')]);
            }
        }

        $created = ExecutiveDocumentRequirement::query()->create([
            'organization_id' => $set->organization_id,
            'project_id' => $set->project_id,
            'document_set_id' => $set->id,
            'work_type_id' => $requirement['work_type_id'] ?? null,
            'project_location_id' => $requirement['project_location_id'] ?? null,
            'completed_work_id' => $requirement['completed_work_id'] ?? null,
            'stage' => (string) ($requirement['stage'] ?? 'document_review'),
            'requirement_key' => (string) ($requirement['requirement_key'] ?? $profile),
            'title' => (string) ($requirement['title'] ?? $profile),
            'profile_type' => $profile,
            'applicability' => $applicability,
            'revision' => 1,
            'source' => $source,
            'source_revision' => $revision,
            'rule_snapshot' => $ruleSnapshot,
            'coverage_scope' => $coverage,
            'evidence' => [],
            'not_applicable_reason' => null,
            'not_applicable_by' => null,
            'not_applicable_at' => null,
        ]);
        $this->recordEvent($created, $actor, 'created', null);

        return $created;
    }

    public function markNotApplicable(ExecutiveDocumentRequirement $requirement, User $actor, string $reason, AuthorizationService $authorization, ?int $expectedRevision = null): ExecutiveDocumentRequirement
    {
        return $this->decideApplicability($requirement, $actor, 'not_applicable', $reason, $authorization, $expectedRevision);
    }

    public function updateConditions(ExecutiveDocumentRequirement $requirement, array $conditions, string $reason, User $actor, AuthorizationService $authorization, int $expectedRevision): ExecutiveDocumentRequirement
    {
        return DB::transaction(function () use ($requirement, $conditions, $reason, $actor, $authorization, $expectedRevision): ExecutiveDocumentRequirement {
            $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($requirement->document_set_id);
            $this->assertCanManage($set, $actor, $authorization, 'executive-documentation.approve');
            $locked = ExecutiveDocumentRequirement::query()->where('document_set_id', $set->id)->lockForUpdate()->findOrFail($requirement->id);
            if ($set->status->value !== 'draft' || $locked->superseded_at !== null) {
                throw ValidationException::withMessages(['conditions' => trans_message('executive_documentation.requirements.frozen')]);
            }
            if (mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages(['reason' => trans_message('executive_documentation.requirements.reason_required')]);
            }
            $current = (array) $locked->rule_snapshot;
            $profile = (array) ($current['profile'] ?? $current);
            try {
                $snapshot = app(ExecutiveDocumentRequirementRuleFactory::class)->snapshot($profile, $conditions);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['conditions' => trans_message('executive_requirements.invalid_conditions')]);
            }
            $snapshot['conditions_decided_by'] = (int) $actor->id;
            $snapshot['conditions_reason'] = trim($reason);
            $snapshot['conditions_decided_at'] = $current['conditions_decided_at'] ?? null;
            if (\Illuminate\Support\Arr::sortRecursive($snapshot) === \Illuminate\Support\Arr::sortRecursive($current)) {
                return $locked;
            }
            $this->assertRevision($locked, $expectedRevision);
            $before = $locked->toArray();
            $snapshot['conditions_decided_at'] = now()->toISOString();
            $locked->forceFill(['rule_snapshot' => $snapshot, 'revision' => $locked->revision + 1])->save();
            $this->recordEvent($locked, $actor, 'conditions_changed', $before);

            return $locked->refresh();
        });
    }

    public function markApplicable(ExecutiveDocumentRequirement $requirement, User $actor, string $reason, AuthorizationService $authorization, ?int $expectedRevision = null): ExecutiveDocumentRequirement
    {
        return $this->decideApplicability($requirement, $actor, 'required', $reason, $authorization, $expectedRevision);
    }

    private function decideApplicability(ExecutiveDocumentRequirement $requirement, User $actor, string $decision, string $reason, AuthorizationService $authorization, ?int $expectedRevision): ExecutiveDocumentRequirement
    {
        $expectedRevision ??= (int) $requirement->revision;

        return DB::transaction(function () use ($requirement, $actor, $decision, $reason, $authorization, $expectedRevision): ExecutiveDocumentRequirement {
            $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($requirement->document_set_id);
            $this->assertCanManage($set, $actor, $authorization, 'executive-documentation.approve');
            $requirement = ExecutiveDocumentRequirement::query()->where('document_set_id', $set->id)->lockForUpdate()->findOrFail($requirement->id);
            if ($set->status->value !== 'draft' || $requirement->superseded_at !== null) {
                throw ValidationException::withMessages(['applicability' => trans_message('executive_documentation.requirements.frozen')]);
            }
            if (mb_strlen(trim($reason)) < 3 || mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages(['reason' => trans_message('executive_documentation.requirements.reason_required')]);
            }
            if ($requirement->applicability === $decision && $requirement->applicability_reason === trim($reason) && (int) $requirement->applicability_by === (int) $actor->id) {
                return $requirement;
            }
            $this->assertRevision($requirement, $expectedRevision);
            $before = $requirement->toArray();
            $requirement->forceFill([
                'applicability' => $decision, 'applicability_reason' => trim($reason),
                'applicability_by' => $actor->id, 'applicability_at' => now(),
                'not_applicable_reason' => $decision === 'not_applicable' ? trim($reason) : null,
                'not_applicable_by' => $decision === 'not_applicable' ? $actor->id : null,
                'not_applicable_at' => $decision === 'not_applicable' ? now() : null,
                'revision' => $requirement->revision + 1,
            ])->save();
            $this->recordEvent($requirement, $actor, $decision === 'required' ? 'applicable' : 'not_applicable', $before);

            return $requirement->refresh();
        });
    }

    public function attachEvidence(ExecutiveDocumentRequirement $requirement, int $versionId, array $coverage, User $actor, AuthorizationService $authorization, ?int $expectedRevision = null): ExecutiveDocumentRequirement
    {
        try {
            $coverage = app(ExecutiveDocumentCoverageDeclaration::class)->normalizeScope($coverage);
        } catch (\DomainException) {
            throw ValidationException::withMessages(['coverage' => trans_message('executive_documentation.requirements.coverage_invalid')]);
        }
        $expectedRevision ??= (int) $requirement->revision;

        return DB::transaction(function () use ($requirement, $versionId, $coverage, $actor, $authorization, $expectedRevision): ExecutiveDocumentRequirement {
            $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($requirement->document_set_id);
            $this->assertCanManage($set, $actor, $authorization);
            $locked = ExecutiveDocumentRequirement::query()->where('document_set_id', $set->id)->lockForUpdate()->findOrFail($requirement->id);
            if ($set->status->value !== 'draft' || $locked->superseded_at !== null) {
                throw ValidationException::withMessages(['evidence' => trans_message('executive_documentation.requirements.frozen')]);
            }
            if (! $this->validEvidence(['version_id' => $versionId, 'coverage' => $coverage], $locked, $set)) {
                throw ValidationException::withMessages(['evidence' => trans_message('executive_requirements.evidence_mismatch')]);
            }
            $evidence = (array) ($locked->evidence ?? []);
            foreach ($evidence as $existing) {
                if ((int) ($existing['version_id'] ?? 0) === $versionId && is_array($existing['coverage'] ?? null)
                    && \Illuminate\Support\Arr::sortRecursive($existing['coverage']) === \Illuminate\Support\Arr::sortRecursive($coverage)) {
                    return $locked;
                }
            }
            $this->assertRevision($locked, $expectedRevision);
            $before = $locked->toArray();
            $evidence[] = ['version_id' => $versionId, 'coverage' => $coverage, 'attached_at' => now()->toISOString()];
            $locked->forceFill(['evidence' => $evidence, 'revision' => $locked->revision + 1])->save();
            $this->recordEvent($locked, $actor, 'evidence_attached', $before);

            return $locked->refresh();
        });
    }

    public function readiness(ExecutiveDocumentSet $set): array
    {
        $requirements = ExecutiveDocumentRequirement::query()->where('document_set_id', $set->id)->whereNull('superseded_at')->get();
        $versionIds = $requirements->flatMap(static fn (ExecutiveDocumentRequirement $requirement) => collect((array) $requirement->evidence)->pluck('version_id'))->filter()->unique()->values()->all();
        $versions = ExecutiveDocumentVersion::query()->with('document')->whereIn('id', $versionIds)->get()->keyBy('id')->all();
        $documentIds = array_values(array_unique(array_map(static fn (ExecutiveDocumentVersion $version): int => (int) $version->document_id, $versions)));
        $latestVersionIds = ExecutiveDocumentVersion::query()->whereIn('document_id', $documentIds)
            ->selectRaw('document_id, MAX(id) AS latest_id')->groupBy('document_id')->pluck('latest_id', 'document_id')->all();
        $applicable = $requirements->where('applicability', 'required');
        $blockers = [];
        $satisfied = 0;
        foreach ($requirements as $requirement) {
            if ($requirement->applicability === 'not_applicable') {
                continue;
            }
            if ($requirement->applicability === 'conditional') {
                $blockers[] = $this->blocker('applicability_unresolved', $requirement, trans_message('executive_documentation.requirements.applicability_unresolved'));

                continue;
            }
            $evidence = $this->evidenceFor($requirement, $set, $versions, $latestVersionIds);
            if ($evidence === []) {
                $blockers[] = $this->evidenceBlocker($requirement, $set, $versions, $latestVersionIds);
            } else {
                $satisfied++;
            }
        }
        if ($requirements->isEmpty()) {
            $blockers[] = ['code' => 'requirements_not_configured', 'requirement_id' => null, 'scope_id' => $set->project_id, 'stage' => 'document_review', 'message' => trans_message('executive_requirements.not_configured'), 'target' => ['type' => 'document_set', 'id' => $set->id]];
        }
        if ($set->status->value === 'draft') {
            $approvedList = $set->approved_list_id === null ? null : ExecutiveDocumentApprovedList::query()
                ->where('organization_id', $set->organization_id)->where('project_id', $set->project_id)->find($set->approved_list_id);
            if ($approvedList === null) {
                $blockers[] = ['code' => 'approved_list_missing', 'requirement_id' => null, 'scope_id' => $set->project_id,
                    'stage' => 'document_review', 'message' => 'Приложите утверждённый перечень ИД объекта.',
                    'target' => ['type' => 'document_set', 'id' => $set->id]];
            } else {
                $items = collect($approvedList->items)->keyBy('key');
                foreach ($requirements as $requirement) {
                    $item = $items->get($requirement->requirement_key);
                    if ($item === null || $item['profile_type'] !== $requirement->profile_type
                        || $requirement->source_revision !== 'approved-list-'.$approvedList->id) {
                        $blockers[] = $this->blocker('approved_list_mismatch', $requirement, 'Пункт комплекта не соответствует утверждённому перечню.');
                    }
                }
            }
        }

        return ['requirements_total' => $requirements->count(), 'requirements_applicable' => $applicable->count(), 'requirements_satisfied' => max(0, $satisfied), 'missing_requirements' => count($blockers), 'blockers' => $blockers, 'ready' => $blockers === []];
    }

    public function assertReadyForTransmission(ExecutiveDocumentSet $set): array
    {
        return DB::transaction(function () use ($set): array {
            $lockedSet = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($set->id);
            $result = $this->readiness($lockedSet);
            if (! $result['ready']) {
                $codes = array_column($result['blockers'], 'code');
                $message = in_array('requirements_not_configured', $codes, true)
                    ? trans_message('executive_requirements.not_configured')
                    : trans_message('executive_requirements.not_ready');
                throw ValidationException::withMessages(['requirements' => $message]);
            }

            return $result;
        });
    }

    private function evidenceFor(ExecutiveDocumentRequirement $requirement, ExecutiveDocumentSet $set, array $versions, array $latestVersionIds): array
    {
        $explicit = (array) ($requirement->evidence ?? []);

        return $explicit === [] ? [] : array_values(array_filter($explicit, fn ($item): bool => is_array($item) && $this->validEvidence($item, $requirement, $set, $versions, $latestVersionIds)));
    }

    private function evidenceBlocker(ExecutiveDocumentRequirement $requirement, ExecutiveDocumentSet $set, array $versions, array $latestVersionIds): array
    {
        foreach (array_reverse((array) $requirement->evidence) as $item) {
            $version = $versions[(int) ($item['version_id'] ?? 0)] ?? null;
            $document = $version?->document;
            if ($document === null || (int) $document->document_set_id !== (int) $set->id
                || (int) $document->organization_id !== (int) $set->organization_id
                || (int) $document->project_id !== (int) $set->project_id) {
                continue;
            }
            $issues = app(ExecutiveDocumentRequirementEvidenceValidator::class)->violations($requirement, $version);
            if ((int) ($latestVersionIds[$document->id] ?? 0) !== (int) $version->id) {
                $issues[] = ['code' => 'evidence_version_outdated', 'field' => 'version_id', 'message' => trans_message('executive_requirements.evidence_version_outdated')];
            }
            if (! in_array((string) $version->status, ['approved', 'transmitted'], true)) {
                $issues[] = ['code' => 'evidence_not_approved', 'field' => 'status', 'message' => trans_message('executive_requirements.evidence_not_approved')];
            }
            if ($issues === []) {
                $issues[] = ['code' => 'coverage_mismatch', 'field' => 'coverage', 'message' => trans_message('executive_requirements.coverage_mismatch')];
            }

            return array_replace($this->blocker('invalid_document_evidence', $requirement, trans_message('executive_requirements.invalid_document_evidence')), [
                'target' => ['type' => 'executive_document', 'id' => $document->id, 'version_id' => $version->id],
                'issues' => $issues,
            ]);
        }

        return $this->blocker('missing_expected_document', $requirement, trans_message('executive_requirements.missing_expected_document'));
    }

    private function validEvidence(array $item, ExecutiveDocumentRequirement $requirement, ExecutiveDocumentSet $set, ?array $versions = null, ?array $latestVersionIds = null): bool
    {
        $versionId = (int) ($item['version_id'] ?? 0);
        $version = $versions === null
            ? ExecutiveDocumentVersion::query()->with('document')->whereKey($versionId)->first()
            : ($versions[$versionId] ?? null);
        if ($version === null || ! in_array((string) $version->status, ['approved', 'transmitted'], true) || $version->document === null) {
            return false;
        }
        if (app(ExecutiveDocumentRequirementEvidenceValidator::class)->violations($requirement, $version) !== []) {
            return false;
        }
        $document = $version->document;
        $latestId = $latestVersionIds === null ? $document->versions()->latest('id')->value('id') : ($latestVersionIds[$document->id] ?? null);
        if ((int) $latestId !== (int) $version->id) {
            return false;
        }
        if ((int) $document->organization_id !== (int) $set->organization_id || (int) $document->project_id !== (int) $set->project_id || (int) $document->document_set_id !== (int) $set->id || $document->document_type->value !== $requirement->profile_type) {
            return false;
        }
        if ($requirement->work_type_id !== null && (int) $document->work_type_id !== (int) $requirement->work_type_id) {
            return false;
        }
        $coverage = $item['coverage'] ?? null;
        $basisCoverage = (array) (($version->basis_snapshot ?? [])['coverage'] ?? []);
        if (! is_array($coverage) || ! $this->coverageMatches($coverage, (array) ($requirement->coverage_scope ?? []))
            || ! $this->coverageMatches($basisCoverage, (array) ($requirement->coverage_scope ?? []))
            || ! $this->coverageMatches($basisCoverage, $coverage)) {
            return false;
        }
        $basis = (array) ($version->basis_snapshot ?? []);
        if ($requirement->completed_work_id !== null && (int) ($basis['completed_work_id'] ?? 0) !== (int) $requirement->completed_work_id) {
            return false;
        }
        if ($requirement->work_type_id !== null && (int) ($basis['document']['work_type_id'] ?? $basis['work_type_id'] ?? 0) !== (int) $requirement->work_type_id) {
            return false;
        }
        $locationId = $requirement->project_location_id ?? ($requirement->coverage_scope ?? [])['project_location_id'] ?? null;

        return $locationId === null || (int) ($basisCoverage['project_location_id'] ?? 0) === (int) $locationId;
    }

    private function assertCanApprove(ExecutiveDocumentSet $set, User $actor, AuthorizationService $authorization): void
    {
        if (! $authorization->can($actor, 'executive-documentation.approve', [
            'organization_id' => (int) $set->organization_id,
            'project_id' => (int) $set->project_id,
            'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }
    }

    private function assertRevision(ExecutiveDocumentRequirement $requirement, int $expectedRevision): void
    {
        if ((int) $requirement->revision !== $expectedRevision) {
            throw new BusinessLogicException(trans_message('executive_documentation.requirements.revision_conflict'), 409);
        }
    }

    private function recordEvent(ExecutiveDocumentRequirement $requirement, User $actor, string $action, ?array $before, ?string $operationKey = null, ?string $requestHash = null): void
    {
        DB::table('executive_document_requirement_events')->insert([
            'requirement_id' => $requirement->id,
            'document_set_id' => $requirement->document_set_id,
            'organization_id' => $requirement->organization_id,
            'actor_id' => $actor->id,
            'action' => $action,
            'operation_key' => $operationKey,
            'request_hash' => $requestHash,
            'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_snapshot' => json_encode($requirement->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function assertCanManage(ExecutiveDocumentSet $set, User $actor, AuthorizationService $authorization, string $permission = 'executive-documentation.edit'): void
    {
        $organizationId = (int) $set->organization_id;
        $project = $set->project;
        if ((int) $actor->current_organization_id !== $organizationId
            || ! $actor->belongsToOrganization($organizationId)
            || $project === null
            || ! app(UserProjectAccessService::class)->canAccessProject($actor, $project, $organizationId)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
        if (! $authorization->can($actor, $permission, [
            'organization_id' => $organizationId,
            'project_id' => (int) $set->project_id,
            'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }
    }

    private function assertScopedReference(ExecutiveDocumentSet $set, array $requirement): void
    {
        if (isset($requirement['work_type_id']) && ! WorkType::query()->whereKey($requirement['work_type_id'])->where('organization_id', $set->organization_id)->exists()) {
            throw ValidationException::withMessages(['work_type_id' => trans_message('executive_requirements.work_type_unavailable')]);
        }
        if (isset($requirement['completed_work_id']) && ! CompletedWork::query()->whereKey($requirement['completed_work_id'])->where('organization_id', $set->organization_id)->where('project_id', $set->project_id)->exists()) {
            throw ValidationException::withMessages(['completed_work_id' => trans_message('executive_requirements.work_unavailable')]);
        }
        if (isset($requirement['project_location_id']) && ! ProjectLocation::query()->whereKey($requirement['project_location_id'])->where('organization_id', $set->organization_id)->where('project_id', $set->project_id)->exists()) {
            throw ValidationException::withMessages(['project_location_id' => trans_message('executive_requirements.location_unavailable')]);
        }
    }

    private function coverageMatches(array $coverage, array $scope): bool
    {
        return app(ExecutiveDocumentCoverageDeclaration::class)->covers($coverage, $scope);
    }

    private function blocker(string $code, ExecutiveDocumentRequirement $requirement, string $message): array
    {
        return ['code' => $code, 'requirement_id' => (string) $requirement->id, 'scope_id' => (int) ($requirement->project_location_id ?? $requirement->work_type_id ?? $requirement->project_id), 'stage' => $requirement->stage, 'message' => $message, 'target' => ['type' => 'requirement', 'id' => $requirement->id]];
    }
}
