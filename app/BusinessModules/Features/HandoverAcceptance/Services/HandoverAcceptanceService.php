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
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services\HandoverEvidenceEventRecorder;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class HandoverAcceptanceService
{
    private const SCOPE_RELATIONS = [
        'project:id,name',
        'location',
        'checklists.items',
        'sessions.findings.qualityDefect',
        'findings.qualityDefect',
        'signoffs',
        'handoverPackage.documents',
    ];

    public function __construct(
        private readonly FileService $fileService,
        private readonly HandoverEvidenceEventRecorder $evidenceEvents,
    ) {}

    public function listScopes(int $organizationId, array $filters = []): Collection
    {
        return AcceptanceScope::query()
            ->where('organization_id', $organizationId)
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
            $checklist = AcceptanceChecklist::query()->create([
                'organization_id' => $scope->organization_id,
                'project_id' => $scope->project_id,
                'acceptance_scope_id' => $scope->id,
                'title' => $data['title'],
                'status' => 'active',
            ]);

            foreach ($data['items'] as $item) {
                $checklist->items()->create([
                    'code' => $item['code'],
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

    public function startScope(AcceptanceScope $scope): AcceptanceScope
    {
        $this->assertStatus($scope, ['planned', 'reopened', 'rejected']);
        $scope->update(['status' => 'in_progress']);

        return $scope->fresh(self::SCOPE_RELATIONS);
    }

    public function addFinding(AcceptanceSession $session, int $userId, array $data): AcceptanceFinding
    {
        return DB::transaction(function () use ($session, $userId, $data): AcceptanceFinding {
            $scope = $session->scope()->firstOrFail();
            $qualityDefect = null;
            $occurredAt = CarbonImmutable::now();

            if ($data['create_quality_defect'] === true) {
                $qualityDefect = QualityDefect::query()->create([
                    'organization_id' => $session->organization_id,
                    'project_id' => $session->project_id,
                    'created_by' => $userId,
                    'defect_number' => 'HA-'.$session->id.'-'.now()->format('His'),
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'severity' => $data['severity'],
                    'status' => 'open',
                    'location_name' => $scope->location?->path,
                    'inspection_required' => (bool) $data['quality_defect_inspection_required'],
                    'metadata' => [
                        'source' => [
                            'type' => 'acceptance_finding',
                            'acceptance_scope_id' => (int) $scope->id,
                            'acceptance_session_id' => (int) $session->id,
                        ],
                    ],
                ]);
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
            $this->evidenceEvents->record(
                $scope,
                'finding_opened',
                'acceptance_finding',
                (int) $finding->id,
                $occurredAt,
                $userId,
            );
            if ($qualityDefect instanceof QualityDefect) {
                $this->evidenceEvents->record(
                    $scope,
                    'blocker_opened',
                    'quality_defect',
                    (int) $qualityDefect->id,
                    $occurredAt,
                    $userId,
                );
            }

            return $finding->fresh(['qualityDefect']);
        });
    }

    public function resolveFinding(AcceptanceFinding $finding, int $userId, array $data): AcceptanceFinding
    {
        if ($finding->status !== 'open') {
            throw new DomainException(trans_message('handover_acceptance.errors.finding_resolve_invalid_status'));
        }

        return DB::transaction(function () use ($finding, $userId, $data): AcceptanceFinding {
            $occurredAt = CarbonImmutable::now();
            $finding->update([
                'status' => 'resolved',
                'resolved_by_user_id' => $userId,
                'resolution_comment' => $data['resolution_comment'],
                'resolved_at' => $occurredAt,
            ]);
            $scope = $finding->scope()->firstOrFail();
            $this->evidenceEvents->record(
                $scope,
                'finding_resolved',
                'acceptance_finding',
                (int) $finding->id,
                $occurredAt,
                $userId,
            );

            return $finding->fresh(['qualityDefect']);
        }, 3);
    }

    public function reviewChecklistItem(
        AcceptanceChecklistItem $item,
        int $userId,
        array $data,
    ): AcceptanceChecklistItem {
        return DB::transaction(function () use ($item, $userId, $data): AcceptanceChecklistItem {
            $occurredAt = CarbonImmutable::now();
            $item->update([
                'status' => $data['status'],
                'reviewed_at' => $occurredAt,
                'reviewed_by_user_id' => $userId,
                'comment' => $data['comment'] ?? null,
            ]);
            $checklist = $item->checklist()->firstOrFail();
            $this->refreshChecklistStatus($checklist);
            $scope = $checklist->scope()->firstOrFail();
            $this->evidenceEvents->record(
                $scope,
                'checklist_reviewed',
                'acceptance_checklist_item',
                (int) $item->id,
                $occurredAt,
                $userId,
            );

            return $item->fresh(['checklist.items']);
        }, 3);
    }

    public function markReadyForReinspection(AcceptanceScope $scope): AcceptanceScope
    {
        if ($this->openFindingsCount($scope) > 0) {
            throw new DomainException(trans_message('handover_acceptance.errors.open_findings_block_ready'));
        }

        $this->assertStatus($scope, ['findings_open', 'in_progress', 'rejected']);

        return DB::transaction(function () use ($scope): AcceptanceScope {
            $occurredAt = CarbonImmutable::now();
            $scope->update(['status' => 'ready_for_reinspection']);
            $this->evidenceEvents->record(
                $scope,
                'inspection_attempted',
                'inspection',
                (int) $scope->id,
                $occurredAt,
                null,
            );

            return $scope->fresh(self::SCOPE_RELATIONS);
        }, 3);
    }

    public function acceptScope(AcceptanceScope $scope, int $userId, ?string $comment): AcceptanceScope
    {
        if ($this->openFindingsCount($scope) > 0) {
            $scope->update(['status' => 'findings_open']);
            throw new DomainException(trans_message('handover_acceptance.errors.open_findings_block_accept'));
        }

        $this->assertStatus($scope, ['in_progress', 'ready_for_reinspection', 'rejected']);

        return DB::transaction(function () use ($scope, $userId, $comment): AcceptanceScope {
            $occurredAt = CarbonImmutable::now();
            $wasReinspection = $scope->status === 'ready_for_reinspection';
            $scope->update(['status' => 'accepted', 'accepted_at' => $occurredAt]);
            $this->sign($scope, $userId, 'accepted', $comment);
            if ($wasReinspection) {
                $this->evidenceEvents->record(
                    $scope,
                    'inspection_resulted',
                    'inspection',
                    (int) $scope->id,
                    $occurredAt,
                    $userId,
                );
            }
            $this->evidenceEvents->record(
                $scope,
                'scope_accepted',
                'acceptance_scope',
                (int) $scope->id,
                $occurredAt,
                $userId,
            );

            return $scope->fresh(self::SCOPE_RELATIONS);
        }, 3);
    }

    public function rejectScope(AcceptanceScope $scope, int $userId, string $reason): AcceptanceScope
    {
        $this->assertStatus($scope, ['in_progress', 'findings_open', 'ready_for_reinspection']);

        return DB::transaction(function () use ($scope, $userId, $reason): AcceptanceScope {
            $occurredAt = CarbonImmutable::now();
            $wasReinspection = $scope->status === 'ready_for_reinspection';
            $scope->update(['status' => 'rejected']);
            $this->sign($scope, $userId, 'rejected', $reason);
            if ($wasReinspection) {
                $this->evidenceEvents->record(
                    $scope,
                    'inspection_resulted',
                    'inspection',
                    (int) $scope->id,
                    $occurredAt,
                    $userId,
                );
            }
            $this->evidenceEvents->record(
                $scope,
                'scope_rejected',
                'acceptance_scope',
                (int) $scope->id,
                $occurredAt,
                $userId,
            );

            return $scope->fresh(self::SCOPE_RELATIONS);
        }, 3);
    }

    public function createPackage(AcceptanceScope $scope, int $userId, array $data): HandoverPackage
    {
        return DB::transaction(function () use ($scope, $userId, $data): HandoverPackage {
            $package = HandoverPackage::query()->updateOrCreate(
                ['acceptance_scope_id' => $scope->id],
                [
                    'organization_id' => $scope->organization_id,
                    'project_id' => $scope->project_id,
                    'created_by_user_id' => $userId,
                    'title' => $data['title'],
                    'status' => 'draft',
                ]
            );

            $occurredAt = CarbonImmutable::now();
            foreach ($package->documents()->lockForUpdate()->get() as $existingDocument) {
                if ($existingDocument->status === 'approved') {
                    $this->evidenceEvents->record(
                        $scope,
                        'document_approval_reversed',
                        'handover_document',
                        (int) $existingDocument->id,
                        $occurredAt,
                        $userId,
                    );
                }
                $this->evidenceEvents->record(
                    $scope,
                    'document_deleted',
                    'handover_document',
                    (int) $existingDocument->id,
                    $occurredAt,
                    $userId,
                );
                $existingDocument->delete();
            }

            foreach ($data['documents'] as $document) {
                if ($document['status'] === 'approved') {
                    throw new DomainException(
                        trans_message('handover_acceptance.errors.document_requires_explicit_approval'),
                    );
                }
                $package->documents()->create([
                    'title' => $document['title'],
                    'document_type' => $document['document_type'],
                    'is_required' => (bool) $document['is_required'],
                    'status' => $document['status'],
                    'external_url' => $document['external_url'] ?? null,
                    'approved_at' => null,
                ]);
            }

            return $package->fresh(['documents']);
        });
    }

    public function approveDocument(
        HandoverPackageDocument $document,
        int $userId,
        array $data,
    ): HandoverPackageDocument {
        return DB::transaction(function () use ($document, $userId, $data): HandoverPackageDocument {
            $lockedDocument = HandoverPackageDocument::query()
                ->whereKey((int) $document->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedDocument->status === 'approved') {
                return $lockedDocument;
            }
            $occurredAt = CarbonImmutable::now();
            $lockedDocument->update([
                'status' => 'approved',
                'external_url' => $data['external_url'] ?? $lockedDocument->external_url,
                'approved_at' => $occurredAt,
                'approved_by_user_id' => $userId,
            ]);
            $scope = $lockedDocument->package()->firstOrFail()->scope()->firstOrFail();
            $this->evidenceEvents->record(
                $scope,
                'document_approved',
                'handover_document',
                (int) $lockedDocument->id,
                $occurredAt,
                $userId,
            );

            return $lockedDocument->fresh();
        }, 3);
    }

    public function uploadDocument(HandoverPackageDocument $document, UploadedFile $file): HandoverPackageDocument
    {
        $package = $document->package()->firstOrFail();
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

        return DB::transaction(function () use ($document, $url): HandoverPackageDocument {
            $lockedDocument = HandoverPackageDocument::query()
                ->whereKey((int) $document->id)
                ->lockForUpdate()
                ->firstOrFail();
            $package = $lockedDocument->package()->firstOrFail();
            $occurredAt = CarbonImmutable::now();
            $scope = $package->scope()->firstOrFail();
            if ($lockedDocument->status === 'approved') {
                $this->evidenceEvents->record(
                    $scope,
                    'document_approval_reversed',
                    'handover_document',
                    (int) $lockedDocument->id,
                    $occurredAt,
                    null,
                );
            }
            $lockedDocument->update([
                'status' => 'draft',
                'external_url' => $url,
                'approved_at' => null,
                'approved_by_user_id' => null,
            ]);
            $this->evidenceEvents->record(
                $scope,
                'document_replaced',
                'handover_document',
                (int) $lockedDocument->id,
                $occurredAt,
                null,
            );

            return $lockedDocument->fresh(['package.documents']);
        }, 3);
    }

    public function handoverScope(AcceptanceScope $scope, int $userId): AcceptanceScope
    {
        $this->assertStatus($scope, ['accepted']);

        $package = $scope->handoverPackage()->with('documents')->first();
        $missingRequiredDocuments = $package === null
            || $package->documents->contains(fn (HandoverPackageDocument $document): bool => $document->is_required && $document->status !== 'approved');

        if ($missingRequiredDocuments) {
            throw new DomainException(trans_message('handover_acceptance.errors.required_documents_block_handover'));
        }

        return DB::transaction(function () use ($package, $scope, $userId): AcceptanceScope {
            $occurredAt = CarbonImmutable::now();
            $package->update(['status' => 'approved']);
            $scope->update(['status' => 'handed_over', 'handed_over_at' => $occurredAt]);
            $this->sign($scope, $userId, 'handed_over', null);
            $this->evidenceEvents->record(
                $scope,
                'scope_handed_over',
                'acceptance_scope',
                (int) $scope->id,
                $occurredAt,
                $userId,
            );

            return $scope->fresh(self::SCOPE_RELATIONS);
        }, 3);
    }

    public function reopenScope(AcceptanceScope $scope, int $userId, string $reason): AcceptanceScope
    {
        $this->assertStatus($scope, ['accepted', 'handed_over']);

        return DB::transaction(function () use ($scope, $userId, $reason): AcceptanceScope {
            $occurredAt = CarbonImmutable::now();
            $scope->update(['status' => 'reopened', 'reopened_at' => $occurredAt]);
            $this->sign($scope, $userId, 'reopened', $reason);
            $this->evidenceEvents->record(
                $scope,
                'scope_reopened',
                'acceptance_scope',
                (int) $scope->id,
                $occurredAt,
                $userId,
            );

            return $scope->fresh(self::SCOPE_RELATIONS);
        }, 3);
    }

    public function findScope(int $organizationId, int $id): AcceptanceScope
    {
        return AcceptanceScope::query()
            ->where('organization_id', $organizationId)
            ->with(self::SCOPE_RELATIONS)
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.scope_not_found'));
    }

    public function findSession(int $organizationId, int $id): AcceptanceSession
    {
        return AcceptanceSession::query()
            ->where('organization_id', $organizationId)
            ->with(['scope.location'])
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.session_not_found'));
    }

    public function findFinding(int $organizationId, int $id): AcceptanceFinding
    {
        return AcceptanceFinding::query()
            ->where('organization_id', $organizationId)
            ->with(['qualityDefect'])
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.finding_not_found'));
    }

    public function findChecklistItem(int $organizationId, int $id): AcceptanceChecklistItem
    {
        return AcceptanceChecklistItem::query()
            ->whereHas('checklist', fn ($query) => $query->where('organization_id', $organizationId))
            ->with(['checklist.items'])
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.checklist_item_not_found'));
    }

    public function findPackageDocument(int $organizationId, int $id): HandoverPackageDocument
    {
        return HandoverPackageDocument::query()
            ->whereHas('package', fn ($query) => $query->where('organization_id', $organizationId))
            ->find($id)
            ?? throw new DomainException(trans_message('handover_acceptance.errors.package_document_not_found'));
    }

    private function findProject(int $organizationId, int $projectId): Project
    {
        return Project::query()
            ->where('organization_id', $organizationId)
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

    private function sign(AcceptanceScope $scope, int $userId, string $status, ?string $comment): void
    {
        AcceptanceSignoff::query()->create([
            'organization_id' => $scope->organization_id,
            'project_id' => $scope->project_id,
            'acceptance_scope_id' => $scope->id,
            'signed_by_user_id' => $userId,
            'status' => $status,
            'comment' => $comment,
            'signed_at' => now(),
        ]);
    }
}
