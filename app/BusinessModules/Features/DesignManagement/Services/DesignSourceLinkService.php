<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\CompletedWork;
use App\Models\ConstructionJournalEntry;
use App\Models\EstimateItem;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DesignSourceLinkService
{
    public function __construct(private readonly AuthorizationService $authorization, private readonly AccessController $access) {}

    /** @return array<int, array<string, mixed>> */
    public function linksForSource(User $actor, int $organizationId, int $versionId): array
    {
        $this->requirePirAccess($organizationId);
        $source = $this->source($organizationId, $versionId);
        $this->authorize($actor, 'design-management.view', $organizationId, $source->project_id);

        $links = DesignSourceLink::query()->where('organization_id', $organizationId)->where('project_id', $source->project_id)->where('source_version_id', $versionId)
            ->with('sourceVersion.artifact.package')->orderByDesc('id')->get();

        return $this->readableLinks($actor, $links)
            ->map(fn (DesignSourceLink $link): array => $this->presentLink($actor, $link))->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function linksForTarget(User $actor, int $organizationId, string $targetType, int $targetId): array
    {
        $target = $this->target($targetType, $targetId);
        $this->assertTargetScope($targetType, $target, $organizationId, null);
        $scope = $this->targetScope($targetType, $target);
        $this->authorizeTargetRead($actor, $targetType, $organizationId, $scope['project_id']);

        return DesignSourceLink::query()->where('organization_id', $organizationId)->where('project_id', $scope['project_id'])->where('target_type', $targetType)->where('target_id', $targetId)
            ->with('sourceVersion.artifact.package')->orderByDesc('id')->get()->map(fn (DesignSourceLink $link): array => $this->presentLink($actor, $link))->all();
    }

    public function sourceContext(User $actor, int $organizationId, int $linkId): array
    {
        $this->requirePirAccess($organizationId);
        $link = DesignSourceLink::query()->where('organization_id', $organizationId)->find($linkId);
        if (! $link instanceof DesignSourceLink) {
            throw new DomainException(trans_message('design_links.errors.link_not_found'));
        }
        $target = $this->target($link->target_type, (int) $link->target_id);
        $this->assertTargetScope($link->target_type, $target, $organizationId, (int) $link->project_id);
        $this->authorizeTargetRead($actor, $link->target_type, $organizationId, (int) $link->project_id);
        $source = $this->source($organizationId, (int) $link->source_version_id);
        if ((int) $source->project_id !== (int) $link->project_id) {
            throw new DomainException(trans_message('design_links.errors.source_not_found'));
        }
        $isModel = strtolower((string) $source->file_format) === 'ifc';
        $this->authorize($actor, 'design-management.view', $organizationId, (int) $source->project_id);
        $this->authorize($actor, $isModel ? 'design-management.models.view' : 'design-management.documents.view', $organizationId, (int) $source->project_id);
        $element = $link->source_element_id !== null ? DesignIfcModelElement::query()
            ->where('organization_id', $organizationId)->where('project_id', $source->project_id)
            ->where('version_id', $source->id)->where('express_id', $link->source_element_id)->first() : null;
        if ($link->source_element_id !== null && (! $isModel || ! $element instanceof DesignIfcModelElement)) {
            throw new DomainException(trans_message('design_links.errors.source_element_not_found'));
        }
        $sheet = $link->source_sheet_id !== null ? DesignDocumentSheet::query()
            ->where('organization_id', $organizationId)->where('project_id', $source->project_id)
            ->where('artifact_id', $source->artifact_id)->where('package_id', $source->artifact?->package_id)
            ->where('version_id', $source->id)->find($link->source_sheet_id) : null;
        if ($link->source_sheet_id !== null && ! $sheet instanceof DesignDocumentSheet) {
            throw new DomainException(trans_message('design_links.errors.source_sheet_not_found'));
        }

        return [
            'link_id' => (int) $link->id, 'version_id' => (int) $source->id,
            'target_type' => $link->target_type, 'target_id' => (int) $link->target_id,
            'project_id' => (int) $source->project_id, 'kind' => $isModel ? 'model' : 'document',
            'title' => (string) $source->title, 'revision' => $source->revision_label ?? $source->revision,
            'filename' => $source->source_original_name, 'mime_type' => $source->source_mime_type,
            'sheet' => $sheet?->only(['id', 'sheet_number', 'sheet_title']),
            'element' => $element?->only(['express_id', 'global_id', 'name', 'category']),
        ];
    }

    /** @return array<int, array{id:int,type:string,label:string}> */
    public function searchTargets(User $actor, int $organizationId, int $projectId, string $targetType, string $query): array
    {
        $this->requirePirAccess($organizationId);
        $this->authorize($actor, 'design-management.view', $organizationId, $projectId);
        $this->authorizeTargetRead($actor, $targetType, $organizationId, $projectId);
        $term = trim($query);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $models = match ($targetType) {
            'estimate_item' => EstimateItem::query()->whereHas('estimate', fn ($q) => $q->where('organization_id', $organizationId)->where('project_id', $projectId))->where('name', 'ilike', "%{$term}%")->limit(20)->get(),
            'schedule_task' => ScheduleTask::query()->where('organization_id', $organizationId)->whereHas('schedule', fn ($q) => $q->where('organization_id', $organizationId)->where('project_id', $projectId))->where('name', 'ilike', "%{$term}%")->limit(20)->get(),
            'completed_work' => CompletedWork::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->where('description', 'ilike', "%{$term}%")->limit(20)->get(),
            'construction_journal_entry' => ConstructionJournalEntry::query()
                ->whereHas('journal', fn ($journal) => $journal->where('organization_id', $organizationId)->where('project_id', $projectId))
                ->where(function ($q) use ($term): void {
                    $q->whereRaw('CAST(entry_number AS TEXT) ILIKE ?', ["%{$term}%"])->orWhere('work_description', 'ilike', "%{$term}%");
                })->limit(20)->get(),
            'purchase_request' => PurchaseRequest::query()->where('organization_id', $organizationId)->whereHas('siteRequest', fn ($siteRequest) => $siteRequest->where('organization_id', $organizationId)->where('project_id', $projectId))->where('request_number', 'ilike', "%{$term}%")->limit(20)->get(),
            'executive_document' => ExecutiveDocument::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->whereHas('documentSet', fn ($set) => $set->where('organization_id', $organizationId)->where('project_id', $projectId))->where('title', 'ilike', "%{$term}%")->limit(20)->get(),
            default => collect(),
        };

        return $models->map(fn (Model $target): array => ['id' => (int) $target->getKey(), 'type' => $targetType, 'label' => $this->snapshot($targetType, $target)['label']])->all();
    }

    /** @return array<string, mixed> */
    public function create(User $actor, int $organizationId, array $data): array
    {
        $this->requirePirAccess($organizationId);
        $source = $this->source($organizationId, (int) $data['source_version_id']);
        if (isset($data['source_element_id'], $data['source_sheet_id'])) {
            throw new DomainException(trans_message('design_links.errors.source_kind_conflict'));
        }
        $element = isset($data['source_element_id']) ? DesignIfcModelElement::query()
            ->where('organization_id', $organizationId)->where('project_id', $source->project_id)
            ->where('version_id', $source->id)->where('express_id', $data['source_element_id'])->first() : null;
        if (isset($data['source_element_id']) && (! $element instanceof DesignIfcModelElement || strtolower((string) $source->file_format) !== 'ifc')) {
            throw new DomainException(trans_message('design_links.errors.source_element_not_found'));
        }
        $sheet = isset($data['source_sheet_id']) ? DesignDocumentSheet::query()
            ->whereKey($data['source_sheet_id'])
            ->where('organization_id', $organizationId)
            ->where('project_id', $source->project_id)
            ->where('package_id', $source->artifact->package_id)
            ->where('artifact_id', $source->artifact_id)
            ->where('version_id', $source->id)->first() : null;
        if (isset($data['source_sheet_id']) && ! $sheet instanceof DesignDocumentSheet) {
            throw new DomainException(trans_message('design_links.errors.source_sheet_not_found'));
        }
        $this->authorizeTargetRead($actor, (string) $data['target_type'], $organizationId, $source->project_id);
        $target = $this->target((string) $data['target_type'], (int) $data['target_id']);
        $this->assertTargetScope((string) $data['target_type'], $target, $organizationId, $source->project_id);
        $this->authorize($actor, 'design-management.edit', $organizationId, $source->project_id);

        $link = DB::transaction(function () use ($organizationId, $source, $sheet, $element, $target, $data, $actor): DesignSourceLink {
            DesignArtifactVersion::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $link = DesignSourceLink::query()->firstOrCreate([
                'status' => 'active',
                'source_version_id' => $source->id, 'source_sheet_id' => $sheet?->id, 'source_element_id' => $element?->express_id, 'target_type' => $data['target_type'], 'target_id' => $target->getKey(),
            ], ['organization_id' => $organizationId, 'project_id' => $source->project_id, 'source_snapshot' => $this->sourceSnapshot($source) + ($element ? ['element' => $element->only(['express_id', 'global_id', 'name', 'category'])] : []), 'target_snapshot' => $this->snapshot((string) $data['target_type'], $target), 'created_by' => $actor->id]);

            return $link;
        });

        return $this->presentLink($actor, $link->load('sourceVersion.artifact.package'));
    }

    public function delete(User $actor, int $organizationId, int $linkId, string $reason, int $expectedRevision): void
    {
        $this->requirePirAccess($organizationId);
        if (trim($reason) === '') {
            throw new DomainException(trans_message('design_links.errors.invalid_decision'));
        }
        DB::transaction(function () use ($actor, $organizationId, $linkId, $reason, $expectedRevision): void {
            $link = DesignSourceLink::query()->where('organization_id', $organizationId)->lockForUpdate()->find($linkId);
            if (! $link instanceof DesignSourceLink) {
                throw new DomainException(trans_message('design_links.errors.link_not_found'));
            }
            $this->authorize($actor, 'design-management.edit', $organizationId, $link->project_id);
            $this->assertRevision($link, $expectedRevision);
            if ($link->status !== 'active') {
                throw new DomainException(trans_message('design_links.errors.link_not_active'));
            }
            $link->forceFill(['status' => 'ended', 'ended_reason' => trim($reason), 'ended_at' => now(), 'ended_by' => $actor->id, 'row_version' => $expectedRevision + 1])->save();
            $this->supersedePendingReviews($link, $actor, $reason);
        });
    }

    /** Entry point for issue/new revision. Idempotent per link and version pair. */
    public function createImpactReviewsForRevision(DesignArtifactVersion $previous, DesignArtifactVersion $new): int
    {
        if ($previous->artifact_id !== $new->artifact_id || $previous->organization_id !== $new->organization_id || $previous->project_id !== $new->project_id) {
            throw new DomainException(trans_message('design_links.errors.revision_source_mismatch'));
        }

        return DB::transaction(function () use ($previous, $new): int {
            $count = 0;
            DesignSourceLink::query()->where('organization_id', $previous->organization_id)->where('project_id', $previous->project_id)->where('status', 'active')->where('source_version_id', $previous->id)->orderBy('id')->lockForUpdate()->each(function (DesignSourceLink $link) use ($previous, $new, &$count): void {
                $review = DesignImpactReview::query()->firstOrCreate(['link_id' => $link->id, 'previous_version_id' => $previous->id, 'new_version_id' => $new->id], ['organization_id' => $link->organization_id, 'project_id' => $link->project_id, 'status' => 'pending']);
                if ($review->wasRecentlyCreated) {
                    $count++;
                }
            });

            return $count;
        });
    }

    /** @return array<string, mixed> */
    public function decideReview(User $actor, int $organizationId, int $reviewId, string $decision, string $reason, int $expectedRevision, ?int $newSheetId = null, ?int $newElementId = null): array
    {
        $this->requirePirAccess($organizationId);
        if (! in_array($decision, ['keep_old', 'move_to_new', 'end'], true) || trim($reason) === '') {
            throw new DomainException(trans_message('design_links.errors.invalid_decision'));
        }

        return DB::transaction(function () use ($actor, $organizationId, $reviewId, $decision, $reason, $expectedRevision, $newSheetId, $newElementId): array {
            $review = DesignImpactReview::query()->where('organization_id', $organizationId)->find($reviewId);
            if (! $review instanceof DesignImpactReview) {
                throw new DomainException(trans_message('design_links.errors.review_not_found'));
            }
            $this->authorize($actor, 'design-management.edit', $organizationId, $review->project_id);
            if ($review->status !== 'pending') {
                throw new DomainException(trans_message('design_links.errors.review_already_decided'));
            }
            $link = DesignSourceLink::query()->where('organization_id', $organizationId)->where('project_id', $review->project_id)->lockForUpdate()->find($review->link_id);
            $review = DesignImpactReview::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($reviewId);
            if ($review->status !== 'pending') {
                throw new DomainException(trans_message('design_links.errors.review_already_decided'));
            }
            if (! $link instanceof DesignSourceLink || $link->status !== 'active' || $link->source_version_id !== $review->previous_version_id) {
                throw new DomainException(trans_message('design_links.errors.link_not_active'));
            }
            $this->authorizeTargetRead($actor, $link->target_type, $organizationId, $review->project_id);
            $this->assertTargetScope($link->target_type, $this->target($link->target_type, $link->target_id), $organizationId, $review->project_id);
            $this->assertRevision($link, $expectedRevision);
            $replacement = null;
            if ($decision === 'move_to_new') {
                $previous = $this->source($organizationId, $review->previous_version_id);
                $next = $this->source($organizationId, $review->new_version_id);
                if ($previous->artifact_id !== $next->artifact_id || $next->project_id !== $review->project_id || $previous->project_id !== $review->project_id) {
                    throw new DomainException(trans_message('design_links.errors.revision_source_mismatch'));
                }
                if ($link->source_sheet_id !== null && $newSheetId === null) {
                    throw new DomainException(trans_message('design_links.errors.new_sheet_required'));
                }
                if ($link->source_element_id !== null && $newElementId === null) {
                    throw new DomainException(trans_message('design_links.errors.new_element_required'));
                }
                $replacement = $this->create($actor, $organizationId, [
                    'source_version_id' => $next->id, 'source_sheet_id' => $newSheetId,
                    'source_element_id' => $newElementId,
                    'target_type' => $link->target_type, 'target_id' => $link->target_id,
                ]);
            }
            if ($decision !== 'keep_old') {
                $link->forceFill([
                    'status' => $decision === 'end' ? 'ended' : 'replaced',
                    'ended_reason' => trim($reason), 'ended_at' => now(), 'ended_by' => $actor->id,
                    'replacement_link_id' => $replacement['id'] ?? null,
                ])->save();
            }
            $review->update(['status' => 'decided', 'decision' => $decision, 'reason' => trim($reason), 'decided_by' => $actor->id, 'decided_at' => now()]);
            $link->forceFill(['row_version' => $expectedRevision + 1])->save();
            if ($decision !== 'keep_old') {
                $this->supersedePendingReviews($link, $actor, $reason);
            }

            return $this->presentReview($review);
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function reviewsForSource(User $actor, int $organizationId, int $versionId): array
    {
        $this->requirePirAccess($organizationId);
        $source = $this->source($organizationId, $versionId);
        $this->authorize($actor, 'design-management.view', $organizationId, $source->project_id);

        $reviews = DesignImpactReview::query()->where('organization_id', $organizationId)->where('project_id', $source->project_id)->where('new_version_id', $versionId)
            ->with('link')->orderByDesc('id')->get();
        $links = $reviews->pluck('link')->filter(fn ($link): bool => $link instanceof DesignSourceLink);
        $readableIds = $this->readableLinks($actor, $links)->keyBy('id');

        return $reviews->filter(fn (DesignImpactReview $review): bool => $readableIds->has($review->link_id))
            ->map(fn (DesignImpactReview $review): array => $this->presentReview($review))->values()->all();
    }

    private function source(int $organizationId, int $versionId): DesignArtifactVersion
    {
        $source = DesignArtifactVersion::query()->with('artifact.package')->where('organization_id', $organizationId)->find($versionId);
        if (! $source instanceof DesignArtifactVersion || ! $this->sourceInScope($source, $organizationId, (int) $source->project_id)) {
            throw new DomainException(trans_message('design_links.errors.source_not_found'));
        }

        return $source;
    }

    /** @return array<string, mixed> */
    public function replacementSheets(User $actor, int $organizationId, int $reviewId, int $page): array
    {
        $this->requirePirAccess($organizationId);
        $review = DesignImpactReview::query()->where('organization_id', $organizationId)->find($reviewId);
        if (! $review instanceof DesignImpactReview) {
            throw new DomainException(trans_message('design_links.errors.review_not_found'));
        }
        $this->authorize($actor, 'design-management.view', $organizationId, $review->project_id);
        $next = $this->source($organizationId, $review->new_version_id);
        if ($next->project_id !== $review->project_id) {
            throw new DomainException(trans_message('design_links.errors.revision_source_mismatch'));
        }
        $sheets = DesignDocumentSheet::query()->where('organization_id', $organizationId)
            ->where('project_id', $review->project_id)->where('version_id', $next->id)
            ->orderBy('id')->paginate(50, ['id', 'sheet_number', 'sheet_title'], 'page', max(1, $page));

        return ['data' => $sheets->items(), 'pagination' => ['current_page' => $sheets->currentPage(), 'last_page' => $sheets->lastPage(), 'total' => $sheets->total()]];
    }

    private function assertRevision(DesignSourceLink $link, int $expectedRevision): void
    {
        if ((int) $link->row_version !== $expectedRevision) {
            throw new DomainException(trans_message('design_links.errors.stale_revision'));
        }
    }

    private function supersedePendingReviews(DesignSourceLink $link, User $actor, string $reason): void
    {
        DesignImpactReview::query()->where('organization_id', $link->organization_id)
            ->where('project_id', $link->project_id)->where('link_id', $link->id)->where('status', 'pending')
            ->update(['status' => 'superseded', 'reason' => trim($reason), 'decided_by' => $actor->id, 'decided_at' => now()]);
    }

    private function authorize(User $actor, string $permission, int $organizationId, int $projectId): void
    {
        if (! $this->authorization->can($actor, $permission, ['organization_id' => $organizationId, 'project_id' => $projectId])) {
            throw new DomainException(trans_message('design_links.errors.forbidden'));
        }
    }

    private function requirePirAccess(int $organizationId): void
    {
        if (! $this->access->hasModuleAccess($organizationId, 'design-management')) {
            throw new DomainException(trans_message('design_links.errors.pir_inactive'));
        }
    }

    private function authorizeTargetRead(User $actor, string $targetType, int $organizationId, int $projectId): void
    {
        $this->authorize($actor, $this->targetReadPermission($targetType), $organizationId, $projectId);
        if (! $this->targetModulesActive($organizationId, $targetType)) {
            throw new DomainException(trans_message('design_links.target_module_inactive'));
        }
    }

    private function canReadTarget(User $actor, DesignSourceLink $link): bool
    {
        return $this->authorization->can($actor, $this->targetReadPermission($link->target_type), ['organization_id' => $link->organization_id, 'project_id' => $link->project_id])
            && $this->targetModulesActive($link->organization_id, $link->target_type);
    }

    private function targetModulesActive(int $organizationId, string $targetType): bool
    {
        $modules = match ($targetType) {
            'estimate_item', 'construction_journal_entry' => ['budget-estimates'],
            'schedule_task' => ['schedule-management'],
            'completed_work' => ['workflow-management'],
            'purchase_request' => ['procurement', 'basic-warehouse'],
            'executive_document' => ['executive-documentation', 'project-management', 'contract-management', 'file-management', 'report-templates'],
            default => [],
        };
        if ($modules === []) {
            return false;
        }
        foreach ($modules as $module) {
            if (! $this->access->hasModuleAccess($organizationId, $module)) {
                return false;
            }
        }

        return true;
    }

    /** @param Collection<int, DesignSourceLink> $links
     * @return Collection<int, DesignSourceLink>
     */
    private function readableLinks(User $actor, Collection $links): Collection
    {
        $permitted = $links->filter(fn (DesignSourceLink $link): bool => $this->canReadTarget($actor, $link));
        $readable = [];
        foreach ($permitted->groupBy('target_type') as $type => $group) {
            $targets = $this->targetModels($type, $group->pluck('target_id')->unique()->all())->keyBy(fn (Model $target): int => (int) $target->getKey());
            foreach ($group as $link) {
                $target = $targets->get($link->target_id);
                if (! $target instanceof Model) {
                    continue;
                }
                $scope = $this->targetScope($type, $target);
                if ($scope['organization_id'] === (int) $link->organization_id && $scope['project_id'] === (int) $link->project_id && $scope['project_id'] > 0) {
                    $readable[$link->id] = true;
                }
            }
        }

        return $permitted->filter(fn (DesignSourceLink $link): bool => isset($readable[$link->id]));
    }

    private function targetReadPermission(string $targetType): string
    {
        return match ($targetType) {
            'estimate_item' => 'budget-estimates.view',
            'schedule_task' => 'schedule.view',
            'construction_journal_entry' => 'construction-journal.view',
            'completed_work' => 'completed_works.view',
            'purchase_request' => 'procurement.purchase_requests.view',
            'executive_document' => 'executive-documentation.view',
            default => throw new DomainException(trans_message('design_links.errors.target_not_found')),
        };
    }

    private function target(string $type, int $id): Model
    {
        return $this->targetModels($type, [$id])->first() ?? throw new DomainException(trans_message('design_links.errors.target_not_found'));
    }

    /** @param array<int, int> $ids
     * @return Collection<int, Model>
     */
    private function targetModels(string $type, array $ids): Collection
    {
        return match ($type) {
            'estimate_item' => EstimateItem::query()->with('estimate')->whereKey($ids)->get(),
            'schedule_task' => ScheduleTask::query()->with('schedule')->whereKey($ids)->get(),
            'construction_journal_entry' => ConstructionJournalEntry::query()->with('journal')->whereKey($ids)->get(),
            'completed_work' => CompletedWork::query()->whereKey($ids)->get(),
            'purchase_request' => PurchaseRequest::query()->with('siteRequest')->whereKey($ids)->get(),
            'executive_document' => ExecutiveDocument::query()->with('documentSet')->whereKey($ids)->get(),
            default => collect(),
        };
    }

    /** @return array{organization_id:int,project_id:int} */
    private function targetScope(string $type, Model $target): array
    {
        $parent = match ($type) {
            'schedule_task' => $target->schedule,
            'purchase_request' => $target->siteRequest,
            'executive_document' => $target->documentSet,
            default => null,
        };
        if (in_array($type, ['schedule_task', 'purchase_request', 'executive_document'], true)
            && (! $parent instanceof Model || (int) $parent->organization_id !== (int) $target->organization_id)) {
            return ['organization_id' => 0, 'project_id' => 0];
        }

        if ($type === 'executive_document' && (int) $parent?->project_id !== (int) $target->project_id) {
            return ['organization_id' => 0, 'project_id' => 0];
        }

        return match ($type) {
            'estimate_item' => ['organization_id' => (int) $target->estimate?->organization_id, 'project_id' => (int) $target->estimate?->project_id], 'schedule_task' => ['organization_id' => (int) $target->organization_id, 'project_id' => (int) $target->schedule?->project_id], 'construction_journal_entry' => ['organization_id' => (int) $target->journal?->organization_id, 'project_id' => (int) $target->journal?->project_id], 'completed_work' => ['organization_id' => (int) $target->organization_id, 'project_id' => (int) $target->project_id], 'purchase_request' => ['organization_id' => (int) $target->organization_id, 'project_id' => (int) $target->siteRequest?->project_id], 'executive_document' => ['organization_id' => (int) $target->organization_id, 'project_id' => (int) $target->project_id],
        };
    }

    private function assertTargetScope(string $type, Model $target, int $organizationId, ?int $projectId): void
    {
        $scope = $this->targetScope($type, $target);
        if ($scope['organization_id'] !== $organizationId || $scope['project_id'] < 1 || ($projectId !== null && $scope['project_id'] !== $projectId)) {
            throw new DomainException(trans_message('design_links.errors.target_out_of_scope'));
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(string $type, Model $target): array
    {
        $label = match ($type) {
            'estimate_item' => $target->name, 'schedule_task' => $target->name, 'construction_journal_entry' => $target->entry_number ?: $target->work_description, 'completed_work' => $target->description ?: $target->notes, 'purchase_request' => $target->request_number, 'executive_document' => $target->title ?? $target->document_number,
        };

        return ['type' => $type, 'label' => (string) ($label ?: "#{$target->getKey()}")];
    }

    /** @return array<string, mixed> */
    private function presentLink(User $actor, DesignSourceLink $link): array
    {
        $source = $link->sourceVersion;
        $sourceReadable = $this->access->hasModuleAccess((int) $link->organization_id, 'design-management')
            && $source instanceof DesignArtifactVersion
            && $this->sourceInScope($source, (int) $link->organization_id, (int) $link->project_id)
            && $this->authorization->can($actor, 'design-management.view', ['organization_id' => $link->organization_id, 'project_id' => $link->project_id]);

        return ['id' => $link->id, 'revision' => (int) $link->row_version, 'status' => $link->status, 'ended_reason' => $link->ended_reason, 'ended_at' => $link->ended_at?->toIso8601String(), 'replacement_link_id' => $link->replacement_link_id, 'source_version_id' => $sourceReadable ? $link->source_version_id : null, 'source_sheet_id' => $sourceReadable ? $link->source_sheet_id : null, 'source_element_id' => $sourceReadable ? $link->source_element_id : null, 'target' => ['id' => (int) $link->target_id, 'type' => $link->target_type] + ($link->target_snapshot ?? []), 'created_at' => $link->created_at?->toIso8601String(), 'source' => $sourceReadable ? $this->sourceSnapshot($source) + ['id' => $source->id, 'available' => true, 'element' => $link->source_snapshot['element'] ?? null] : ($link->source_snapshot ? $link->source_snapshot + ['available' => false] : null)];
    }

    private function sourceInScope(DesignArtifactVersion $source, int $organizationId, int $projectId): bool
    {
        $artifact = $source->artifact;
        $package = $artifact?->package;

        return $projectId > 0
            && (int) $source->organization_id === $organizationId
            && (int) $source->project_id === $projectId
            && $artifact !== null
            && (int) $artifact->organization_id === $organizationId
            && (int) $artifact->project_id === $projectId
            && $package !== null
            && (int) $package->organization_id === $organizationId
            && (int) $package->project_id === $projectId;
    }

    /** @return array{title:string,revision:?string} */
    private function sourceSnapshot(DesignArtifactVersion $source): array { return ['title' => (string) $source->title, 'revision' => $source->revision_label ?? $source->revision]; }

    /** @return array<string, mixed> */
    private function presentReview(DesignImpactReview $review): array
    {
        return ['target' => $review->link ? ['id' => (int) $review->link->target_id, 'type' => $review->link->target_type] + ($review->link->target_snapshot ?? []) : null, 'source' => $review->link?->source_snapshot, 'id' => $review->id, 'revision' => (int) $review->link?->row_version, 'source_sheet_id' => $review->link?->source_sheet_id, 'source_element_id' => $review->link?->source_element_id, 'link_id' => $review->link_id, 'previous_version_id' => $review->previous_version_id, 'new_version_id' => $review->new_version_id, 'status' => $review->status, 'decision' => $review->decision, 'reason' => $review->reason, 'decided_at' => $review->decided_at?->toIso8601String()];
    }
}
