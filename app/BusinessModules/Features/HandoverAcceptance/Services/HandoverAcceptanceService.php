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
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\Project;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
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

    public function listScopes(int $organizationId, array $filters = []): Collection
    {
        return AcceptanceScope::query()
            ->where('organization_id', $organizationId)
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['planned_from']), fn ($query) => $query->whereDate('planned_acceptance_date', '>=', (string) $filters['planned_from']))
            ->when(!empty($filters['planned_to']), fn ($query) => $query->whereDate('planned_acceptance_date', '<=', (string) $filters['planned_to']))
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
        $path = trim(($parent?->path ? $parent->path . ' / ' : '') . (string) $data['name']);

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

            if ($data['create_quality_defect'] === true) {
                $qualityDefect = QualityDefect::query()->create([
                    'organization_id' => $session->organization_id,
                    'project_id' => $session->project_id,
                    'created_by' => $userId,
                    'defect_number' => 'HA-' . $session->id . '-' . now()->format('His'),
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

    public function reviewChecklistItem(AcceptanceChecklistItem $item, array $data): AcceptanceChecklistItem
    {
        $item->update([
            'status' => $data['status'],
            'comment' => $data['comment'] ?? null,
        ]);

        $this->refreshChecklistStatus($item->checklist()->firstOrFail());

        return $item->fresh(['checklist.items']);
    }

    public function markReadyForReinspection(AcceptanceScope $scope): AcceptanceScope
    {
        if ($this->openFindingsCount($scope) > 0) {
            throw new DomainException(trans_message('handover_acceptance.errors.open_findings_block_ready'));
        }

        $this->assertStatus($scope, ['findings_open', 'in_progress', 'rejected']);
        $scope->update(['status' => 'ready_for_reinspection']);

        return $scope->fresh(self::SCOPE_RELATIONS);
    }

    public function acceptScope(AcceptanceScope $scope, int $userId, ?string $comment): AcceptanceScope
    {
        if ($this->openFindingsCount($scope) > 0) {
            $scope->update(['status' => 'findings_open']);
            throw new DomainException(trans_message('handover_acceptance.errors.open_findings_block_accept'));
        }

        $this->assertStatus($scope, ['in_progress', 'ready_for_reinspection', 'rejected']);
        $scope->update(['status' => 'accepted', 'accepted_at' => now()]);
        $this->sign($scope, $userId, 'accepted', $comment);

        return $scope->fresh(self::SCOPE_RELATIONS);
    }

    public function rejectScope(AcceptanceScope $scope, int $userId, string $reason): AcceptanceScope
    {
        $this->assertStatus($scope, ['in_progress', 'findings_open', 'ready_for_reinspection']);
        $scope->update(['status' => 'rejected']);
        $this->sign($scope, $userId, 'rejected', $reason);

        return $scope->fresh(self::SCOPE_RELATIONS);
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

            $package->documents()->delete();

            foreach ($data['documents'] as $document) {
                $package->documents()->create([
                    'title' => $document['title'],
                    'document_type' => $document['document_type'] ?? 'executive_document',
                    'is_required' => $document['is_required'] ?? true,
                    'status' => $document['status'] ?? 'missing',
                    'external_url' => $document['external_url'] ?? null,
                    'approved_at' => ($document['status'] ?? null) === 'approved' ? now() : null,
                ]);
            }

            return $package->fresh(['documents']);
        });
    }

    public function approveDocument(HandoverPackageDocument $document, array $data): HandoverPackageDocument
    {
        $document->update([
            'status' => 'approved',
            'external_url' => $data['external_url'] ?? $document->external_url,
            'approved_at' => now(),
        ]);

        return $document->fresh();
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

        $package->update(['status' => 'approved']);
        $scope->update(['status' => 'handed_over', 'handed_over_at' => now()]);
        $this->sign($scope, $userId, 'handed_over', null);

        return $scope->fresh(self::SCOPE_RELATIONS);
    }

    public function reopenScope(AcceptanceScope $scope, int $userId, string $reason): AcceptanceScope
    {
        $this->assertStatus($scope, ['accepted', 'handed_over']);
        $scope->update(['status' => 'reopened', 'reopened_at' => now()]);
        $this->sign($scope, $userId, 'reopened', $reason);

        return $scope->fresh(self::SCOPE_RELATIONS);
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
        if (!in_array($scope->status, $allowed, true)) {
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
