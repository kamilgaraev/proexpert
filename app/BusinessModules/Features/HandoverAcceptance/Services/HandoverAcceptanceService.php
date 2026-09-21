<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Services;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSignoff;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class HandoverAcceptanceService
{
    private const SCOPE_RELATIONS = [
        'project:id,name,organization_id',
        'location',
        'checklists.items',
        'sessions.findings.qualityDefect',
        'findings.qualityDefect',
        'signoffs',
        'handoverPackage.documents',
    ];

    public function __construct(
        private readonly FileService $fileService,
        private readonly QualityDefectService $qualityDefects,
        private readonly HandoverAcceptanceGate $acceptanceGate,
        private readonly HandoverAcceptanceMutationGuard $mutationGuard,
        private readonly HandoverExecutiveEvidenceService $executiveEvidence,
    ) {}

    public function listScopes(int $organizationId, array $filters = []): Collection
    {
        return AcceptanceScope::query()
            ->where('organization_id', $organizationId)
            ->when(array_key_exists('project_ids', $filters), fn ($query) => $query->whereIn('project_id', $filters['project_ids']))
            ->when(! empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(! empty($filters['planned_from']), fn ($query) => $query->whereDate('planned_acceptance_date', '>=', (string) $filters['planned_from']))
            ->when(! empty($filters['planned_to']), fn ($query) => $query->whereDate('planned_acceptance_date', '<=', (string) $filters['planned_to']))
            ->with(self::SCOPE_RELATIONS)
            ->orderByDesc('id')
            ->get();
    }

    public function createLocation(int $organizationId, array $data): ProjectLocation
    {
        $project = $this->findProject($organizationId, (int) $data['project_id']);
        $parent = isset($data['parent_id']) ? $this->findLocation($organizationId, (int) $data['parent_id']) : null;

        if ($parent !== null && (int) $parent->project_id !== (int) $project->id) {
            throw new DomainException(trans_message('handover_acceptance.errors.location_parent_invalid'));
        }

        $level = $parent ? ((int) $parent->level) + 1 : 0;
        $path = trim(($parent?->path ? $parent->path.' / ' : '').(string) $data['name']);

        return ProjectLocation::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'parent_id' => $parent?->id,
            'location_type' => $data['location_type'],
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'path' => $path,
            'level' => $level,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['parent', 'children']);
    }

    public function createScope(int $organizationId, int $userId, array $data): AcceptanceScope
    {
        $project = $this->findProject($organizationId, (int) $data['project_id']);
        $locationId = $data['project_location_id'] ?? null;

        if ($locationId !== null) {
            $location = $this->findLocation($organizationId, (int) $locationId);
            if ((int) $location->project_id !== (int) $project->id) {
                throw new DomainException(trans_message('handover_acceptance.errors.location_not_found'));
            }
        }

        return AcceptanceScope::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'project_location_id' => $locationId,
            'created_by_user_id' => $userId,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'planned',
            'planned_acceptance_date' => $data['planned_acceptance_date'] ?? null,
        ])->fresh(self::SCOPE_RELATIONS);
    }

    public function addChecklist(AcceptanceScope $scope, array $data): AcceptanceChecklist
    {
        return DB::transaction(function () use ($scope, $data): AcceptanceChecklist {
            $scope = AcceptanceScope::query()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($scope, ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'rejected', 'reopened']);
            $checklist = AcceptanceChecklist::query()->create([
                'organization_id' => $scope->organization_id,
                'project_id' => $scope->project_id,
                'acceptance_scope_id' => $scope->id,
                'title' => $data['title'],
                'status' => 'active',
            ]);

            foreach ($data['items'] as $item) {
                $checklist->items()->create([
                    'title' => $item['title'],
                    'is_required' => $item['is_required'] ?? true,
                    'status' => 'pending',
                    'comment' => $item['comment'] ?? null,
                ]);
            }

            return $checklist->fresh(['items']);
        });
    }

    public function createSession(AcceptanceScope $scope, int $userId, array $data): AcceptanceSession
    {
        return AcceptanceSession::query()->create([
            'organization_id' => $scope->organization_id,
            'project_id' => $scope->project_id,
            'acceptance_scope_id' => $scope->id,
            'created_by_user_id' => $userId,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => 'planned',
            'participant_user_ids' => $data['participant_user_ids'] ?? [],
        ])->fresh(['findings.qualityDefect']);
    }

    public function startScope(AcceptanceScope $scope, int $userId): AcceptanceScope
    {
        return DB::transaction(function () use ($scope, $userId): AcceptanceScope {
            $scope = app(TechnicalAcceptanceQuantityService::class)->lockScopeForDecision($scope);
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.inspect');
            $this->assertStatus($scope, ['planned', 'reopened', 'rejected']);
            $scope->update(['status' => 'in_progress']);

            return $scope->fresh(self::SCOPE_RELATIONS);
        });
    }

    public function addFinding(AcceptanceSession $session, int $userId, array $data): AcceptanceFinding
    {
        return DB::transaction(function () use ($session, $userId, $data): AcceptanceFinding {
            $scope = $session->scope()->lockForUpdate()->firstOrFail();
            $this->assertStatus($scope, ['in_progress', 'findings_open', 'ready_for_reinspection', 'rejected', 'reopened']);
            $qualityDefect = null;

            if ($data['create_quality_defect'] === true) {
                $qualityDefect = $this->qualityDefects->create(
                    (int) $session->organization_id,
                    $userId,
                    [
                        'project_id' => $session->project_id,
                        'title' => $data['title'],
                        'description' => $data['description'] ?? null,
                        'severity' => $data['severity'],
                        'location_name' => $scope->location?->path,
                        'inspection_required' => (bool) $data['quality_defect_inspection_required'],
                        'metadata' => [
                            'source' => [
                                'type' => 'acceptance_finding',
                                'acceptance_scope_id' => (int) $scope->id,
                                'acceptance_session_id' => (int) $session->id,
                            ],
                        ],
                    ],
                );
            }

            $finding = AcceptanceFinding::query()->create([
                'organization_id' => $session->organization_id,
                'project_id' => $session->project_id,
                'acceptance_scope_id' => $scope->id,
                'acceptance_session_id' => $session->id,
                'quality_defect_id' => $qualityDefect?->id,
                'created_by_user_id' => $userId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'severity' => $data['severity'],
                'status' => 'open',
            ]);

            $scope->update(['status' => 'findings_open']);
            $session->update(['status' => 'findings_open']);

            return $finding->fresh(['qualityDefect']);
        });
    }

    public function resolveFinding(AcceptanceFinding $finding, int $userId, array $data): AcceptanceFinding
    {
        if ($finding->status !== 'open') {
            throw new DomainException(trans_message('handover_acceptance.errors.finding_resolve_invalid_status'));
        }

        $finding->update([
            'status' => 'resolved',
            'resolved_by_user_id' => $userId,
            'resolution_comment' => $data['resolution_comment'],
            'resolved_at' => now(),
        ]);

        return $finding->fresh(['qualityDefect']);
    }

    public function reviewChecklistItem(AcceptanceChecklistItem $item, int $userId, array $data): AcceptanceChecklistItem
    {
        return DB::transaction(function () use ($item, $userId, $data): AcceptanceChecklistItem {
            $checklist = $item->checklist()->firstOrFail();
            $scope = $checklist->scope()->lockForUpdate()->firstOrFail();
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.inspect');
            $this->assertStatus($scope, ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'rejected', 'reopened']);
            $item->update(['status' => $data['status'], 'comment' => $data['comment'] ?? null]);
            $this->refreshChecklistStatus($checklist);

            return $item->fresh(['checklist.items']);
        });
    }

    public function markReadyForReinspection(AcceptanceScope $scope, int $userId): AcceptanceScope
    {
        return DB::transaction(function () use ($scope, $userId): AcceptanceScope {
            $scope = app(TechnicalAcceptanceQuantityService::class)->lockScopeForDecision($scope);
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.inspect');
            if ($this->openFindingsCount($scope) > 0) {
                throw new DomainException(trans_message('handover_acceptance.errors.open_findings_block_ready'));
            }

            $this->assertStatus($scope, ['findings_open', 'in_progress', 'rejected']);
            $scope->update(['status' => 'ready_for_reinspection']);

            return $scope->fresh(self::SCOPE_RELATIONS);
        });
    }

    public function acceptScope(AcceptanceScope $scope, int $userId, ?string $comment): AcceptanceScope
    {
        return DB::transaction(function () use ($scope, $userId, $comment): AcceptanceScope {
            $locked = app(TechnicalAcceptanceQuantityService::class)->lockScopeForDecision($scope);
            $this->mutationGuard->assertActor($locked, $userId, 'handover-acceptance.approve');
            $this->lockExecutiveEvidence($locked);
            $this->assertStatus($locked, ['in_progress', 'ready_for_reinspection', 'rejected']);
            $readiness = $this->acceptanceGate->evaluate($locked);
            if (! $readiness['ready']) {
                throw new DomainException($readiness['blockers'][0]['message']);
            }
            $locked->update(['status' => 'accepted', 'accepted_at' => now()]);
            $this->sign($locked, $userId, 'accepted', $comment, $readiness['evidence_snapshot']);

            return $locked->fresh(self::SCOPE_RELATIONS);
        });
    }

    public function rejectScope(AcceptanceScope $scope, int $userId, string $reason): AcceptanceScope
    {
        return DB::transaction(function () use ($scope, $userId, $reason): AcceptanceScope {
            $scope = app(TechnicalAcceptanceQuantityService::class)->lockScopeForDecision($scope);
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.reject');
            $this->assertStatus($scope, ['in_progress', 'findings_open', 'ready_for_reinspection']);
            $scope->update(['status' => 'rejected']);
            $this->sign($scope, $userId, 'rejected', $reason);

            return $scope->fresh(self::SCOPE_RELATIONS);
        });
    }

    public function createPackage(AcceptanceScope $scope, int $userId, array $data): HandoverPackage
    {
        return DB::transaction(function () use ($scope, $userId, $data): HandoverPackage {
            $scope = AcceptanceScope::query()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.edit');
            $this->assertStatus($scope, ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'rejected', 'reopened', 'accepted']);
            \Illuminate\Support\Facades\Validator::make($data, [
                'title' => ['required', 'string', 'max:255'],
                'executive_document_set_id' => ['nullable', 'integer', 'min:1'],
                'documents' => ['present', 'array', 'max:500'],
                'documents.*.title' => ['required', 'string', 'max:255'],
                'documents.*.document_type' => ['required', 'string', 'max:80'],
                'documents.*.is_required' => ['required', 'boolean'],
                'documents.*.status' => ['sometimes', 'in:missing,draft'],
                'documents.*.executive_document_version_id' => ['nullable', 'integer', 'min:1'],
                'documents.*.external_url' => ['prohibited'],
            ])->validate();
            $set = $this->executiveEvidence->resolveSet($scope, isset($data['executive_document_set_id']) ? (int) $data['executive_document_set_id'] : null);
            $package = HandoverPackage::query()->updateOrCreate(
                ['acceptance_scope_id' => $scope->id],
                [
                    'organization_id' => $scope->organization_id,
                    'project_id' => $scope->project_id,
                    'created_by_user_id' => $userId,
                    'title' => $data['title'],
                    'status' => 'draft',
                    'executive_document_set_id' => $set?->id,
                ]
            );

            $package->documents()->delete();

            foreach ($data['documents'] as $document) {
                $binding = isset($document['executive_document_version_id'])
                    ? $this->executiveEvidence->binding($scope, $set, $document['document_type'], (int) $document['executive_document_version_id'])
                    : ['status' => 'missing'];
                $package->documents()->create(array_merge([
                    'title' => $document['title'],
                    'document_type' => $document['document_type'],
                    'is_required' => (bool) $document['is_required'],
                ], $binding));
            }

            return $package->fresh(['documents']);
        });
    }

    public function approveDocument(HandoverPackageDocument $document, int $userId, array $data): HandoverPackageDocument
    {
        return DB::transaction(function () use ($document, $userId, $data): HandoverPackageDocument {
            $scope = $document->package->scope()->lockForUpdate()->firstOrFail();
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.approve');
            $this->assertStatus($scope, ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'rejected', 'reopened', 'accepted']);
            $document = HandoverPackageDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $package = $document->package;
            $set = $this->executiveEvidence->resolveSet($scope, $package->executive_document_set_id);
            if (isset($data['executive_document_version_id'])) {
                $attributes = $this->executiveEvidence->binding($scope, $set, $document->document_type, (int) $data['executive_document_version_id']);
            } else {
                if ($this->executiveEvidence->requiresCanonicalVersion($document->document_type) || empty($document->external_url)
                    || (isset($data['external_url']) && $data['external_url'] !== $document->external_url)) {
                    throw new DomainException(trans_message('handover_acceptance.errors.executive_evidence_invalid'));
                }
                $attributes = ['status' => 'approved', 'approved_at' => now()];
            }
            $document->update($attributes + ['approved_by_user_id' => $userId]);

            return $document->fresh();
        });
    }

    public function uploadDocument(HandoverPackageDocument $document, UploadedFile $file, int $userId): HandoverPackageDocument
    {
        return DB::transaction(function () use ($document, $file, $userId): HandoverPackageDocument {
            $package = $document->package()->firstOrFail();
            $scope = $package->scope()->lockForUpdate()->firstOrFail();
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.submit');
            $this->assertStatus($scope, ['planned', 'in_progress', 'findings_open', 'ready_for_reinspection', 'rejected', 'reopened', 'accepted']);
            if ($this->executiveEvidence->requiresCanonicalVersion($document->document_type) || $document->executive_document_version_id !== null) {
                throw new DomainException(trans_message('handover_acceptance.errors.executive_evidence_invalid'));
            }
            $organization = Organization::query()->find((int) $package->organization_id);

            if (! $organization instanceof Organization) {
                throw new DomainException(trans_message('handover_acceptance.errors.organization_not_found'));
            }

            $url = $this->fileService->upload(
                $file,
                "handover-acceptance/package-documents/{$document->id}",
                null,
                'private',
                $organization
            );

            if ($url === false) {
                throw new DomainException(trans_message('handover_acceptance.errors.document_upload_failed'));
            }

            $document->update([
                'status' => 'draft',
                'external_url' => $url,
                'approved_at' => null,
                'approved_by_user_id' => null,
            ]);

            return $document->fresh(['package.documents']);
        });
    }

    public function handoverScope(AcceptanceScope $scope, int $userId): AcceptanceScope
    {
        return DB::transaction(function () use ($scope, $userId): AcceptanceScope {
            $scope = app(TechnicalAcceptanceQuantityService::class)->lockScopeForDecision($scope);
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.customer-sign');
            $this->assertStatus($scope, ['accepted']);
            $this->lockExecutiveEvidence($scope);
            $readiness = $this->acceptanceGate->evaluate($scope, true);
            if (! $readiness['ready']) {
                throw new DomainException($readiness['blockers'][0]['message']);
            }
            $scope->handoverPackage->update(['status' => 'approved']);
            $scope->update(['status' => 'handed_over', 'handed_over_at' => now()]);
            $this->sign($scope, $userId, 'handed_over', null, $readiness['evidence_snapshot']);

            return $scope->fresh(self::SCOPE_RELATIONS);
        });
    }

    public function reopenScope(AcceptanceScope $scope, int $userId, string $reason): AcceptanceScope
    {
        return DB::transaction(function () use ($scope, $userId, $reason): AcceptanceScope {
            $scope = app(TechnicalAcceptanceQuantityService::class)->lockScopeForDecision($scope);
            $this->mutationGuard->assertActor($scope, $userId, 'handover-acceptance.reject');
            $this->assertStatus($scope, ['accepted', 'handed_over']);
            $scope->update(['status' => 'reopened', 'reopened_at' => now()]);
            $this->sign($scope, $userId, 'reopened', $reason);

            return $scope->fresh(self::SCOPE_RELATIONS);
        });
    }

    public function findScope(int $organizationId, int $id, ?array $projectIds = null): AcceptanceScope
    {
        return AcceptanceScope::query()
            ->where('organization_id', $organizationId)
            ->when($projectIds !== null, fn ($query) => $query->whereIn('project_id', $projectIds))
            ->with(self::SCOPE_RELATIONS)
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.scope_not_found'));
    }

    public function findSession(int $organizationId, int $id, ?array $projectIds = null): AcceptanceSession
    {
        return AcceptanceSession::query()
            ->where('organization_id', $organizationId)
            ->when($projectIds !== null, fn ($query) => $query->whereHas('scope', fn ($scope) => $scope->whereIn('project_id', $projectIds)))
            ->with(['scope.location'])
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.session_not_found'));
    }

    public function findFinding(int $organizationId, int $id, ?array $projectIds = null): AcceptanceFinding
    {
        return AcceptanceFinding::query()
            ->where('organization_id', $organizationId)
            ->when($projectIds !== null, fn ($query) => $query->whereHas('scope', fn ($scope) => $scope->whereIn('project_id', $projectIds)))
            ->with(['qualityDefect'])
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.finding_not_found'));
    }

    public function findChecklistItem(int $organizationId, int $id, ?array $projectIds = null): AcceptanceChecklistItem
    {
        return AcceptanceChecklistItem::query()
            ->whereHas('checklist', fn ($query) => $query->where('organization_id', $organizationId))
            ->when($projectIds !== null, fn ($query) => $query->whereHas('checklist.scope', fn ($scope) => $scope->whereIn('project_id', $projectIds)))
            ->with(['checklist.items'])
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.checklist_item_not_found'));
    }

    public function findPackageDocument(int $organizationId, int $id, ?array $projectIds = null): HandoverPackageDocument
    {
        return HandoverPackageDocument::query()
            ->whereHas('package', fn ($query) => $query->where('organization_id', $organizationId))
            ->when($projectIds !== null, fn ($query) => $query->whereHas('package.scope', fn ($scope) => $scope->whereIn('project_id', $projectIds)))
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.package_document_not_found'));
    }

    private function findProject(int $organizationId, int $projectId): Project
    {
        return Project::query()
            ->accessibleByOrganization($organizationId)
            ->find($projectId)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.project_not_found'));
    }

    private function findLocation(int $organizationId, int $id): ProjectLocation
    {
        return ProjectLocation::query()
            ->where('organization_id', $organizationId)
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.location_not_found'));
    }

    private function assertStatus(AcceptanceScope $scope, array $allowed): void
    {
        if (! in_array($scope->status, $allowed, true)) {
            throw new DomainException(trans_message('handover_acceptance.errors.invalid_status'));
        }
    }

    private function openFindingsCount(AcceptanceScope $scope): int
    {
        return AcceptanceFinding::query()
            ->where('acceptance_scope_id', $scope->id)
            ->where('status', 'open')
            ->count();
    }

    private function refreshChecklistStatus(AcceptanceChecklist $checklist): void
    {
        $items = $checklist->items()->get(['status']);

        if ($items->contains(fn (AcceptanceChecklistItem $item): bool => $item->status === 'rejected')) {
            $checklist->update(['status' => 'findings_open']);

            return;
        }

        if ($items->isNotEmpty() && $items->every(fn (AcceptanceChecklistItem $item): bool => $item->status === 'accepted')) {
            $checklist->update(['status' => 'completed']);

            return;
        }

        $checklist->update(['status' => 'active']);
    }

    private function lockExecutiveEvidence(AcceptanceScope $scope): void
    {
        $package = $scope->handoverPackage()->first();
        if ($package?->executive_document_set_id !== null) {
            $this->executiveEvidence->resolveSet($scope, (int) $package->executive_document_set_id);
        }
    }

    private function sign(AcceptanceScope $scope, int $userId, string $status, ?string $comment, ?array $evidenceSnapshot = null): void
    {
        AcceptanceSignoff::query()->create([
            'organization_id' => $scope->organization_id,
            'project_id' => $scope->project_id,
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $userId,
            'status' => $status,
            'comment' => $comment,
            'signed_at' => now(),
            'evidence_snapshot' => $evidenceSnapshot,
        ]);
    }
}
