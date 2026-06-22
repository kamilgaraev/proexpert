<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Services;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationCampaign;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationDecision;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationException;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationExport;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationRevocation;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Repositories\RoleRepository;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccessRecertificationService
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly AuthorizationService $authorization,
        private readonly ImmutableAuditRecorder $audit,
        private readonly AccessRecertificationEvidenceBuilder $evidence,
        private readonly AccessRecertificationRiskScanner $riskScanner,
        private readonly AccessRecertificationDecisionPolicy $decisionPolicy,
    ) {}

    public function campaigns(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return AccessRecertificationCampaign::query()
            ->forOrganization($organizationId)
            ->with(['owner:id,name', 'createdBy:id,name'])
            ->withCount([
                'items',
                'items as pending_items_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'escalated']),
                'items as overdue_items_count' => fn (Builder $query) => $query
                    ->whereIn('status', ['pending', 'escalated'])
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now()),
                'revocations as pending_revocations_count' => fn (Builder $query) => $query->where('status', 'pending'),
                'exceptions as requested_exceptions_count' => fn (Builder $query) => $query->where('status', 'requested'),
            ])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('name', 'ilike', '%' . $search . '%')
                        ->orWhere('description', 'ilike', '%' . $search . '%');
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function campaignSummary(int $organizationId): array
    {
        $campaigns = AccessRecertificationCampaign::query()->forOrganization($organizationId);
        $items = AccessRecertificationItem::query()->forOrganization($organizationId);

        return [
            'campaigns_total' => (clone $campaigns)->count(),
            'campaigns_active' => (clone $campaigns)->where('status', 'active')->count(),
            'items_pending' => (clone $items)->whereIn('status', ['pending', 'escalated'])->count(),
            'items_overdue' => (clone $items)
                ->whereIn('status', ['pending', 'escalated'])
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count(),
            'dangerous_access' => (clone $items)->whereIn('risk_level', ['high', 'critical'])->count(),
            'exceptions_requested' => AccessRecertificationException::query()
                ->forOrganization($organizationId)
                ->where('status', 'requested')
                ->count(),
            'revocations_pending' => AccessRecertificationRevocation::query()
                ->forOrganization($organizationId)
                ->where('status', 'pending')
                ->count(),
        ];
    }

    public function createCampaign(int $organizationId, User $actor, array $data): AccessRecertificationCampaign
    {
        return DB::transaction(function () use ($organizationId, $actor, $data): AccessRecertificationCampaign {
            $campaign = AccessRecertificationCampaign::query()->create([
                'organization_id' => $organizationId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'periodic',
                'status' => $data['status'] ?? 'draft',
                'risk_mode' => $data['risk_mode'] ?? 'risk_based',
                'scope' => $data['scope'] ?? [],
                'owner_user_id' => $data['owner_user_id'],
                'escalation_user_id' => $data['escalation_user_id'] ?? null,
                'starts_at' => $data['starts_at'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'created_by_user_id' => $actor->id,
                'correlation_id' => (string) Str::uuid(),
            ]);

            $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.campaign.created',
                'create',
                'access_recertification_campaign',
                $campaign->id,
                $campaign->name,
                $campaign->correlation_id,
                'arc:campaign:' . $campaign->id . ':created',
                null,
                [],
                $campaign->only(['name', 'type', 'status', 'scope', 'owner_user_id', 'due_at'])
            );

            return $campaign->refresh();
        });
    }

    public function updateCampaign(
        AccessRecertificationCampaign $campaign,
        int $organizationId,
        User $actor,
        array $data
    ): AccessRecertificationCampaign {
        $this->assertCampaignOrganization($campaign, $organizationId);

        if (!in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw new InvalidArgumentException('campaign_locked');
        }

        return DB::transaction(function () use ($campaign, $organizationId, $actor, $data): AccessRecertificationCampaign {
            $before = $campaign->only(['name', 'description', 'type', 'status', 'risk_mode', 'scope', 'owner_user_id', 'escalation_user_id', 'starts_at', 'due_at']);
            $campaign->fill($data);
            $campaign->save();

            $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.campaign.updated',
                'update',
                'access_recertification_campaign',
                $campaign->id,
                $campaign->name,
                $campaign->correlation_id,
                'arc:campaign:' . $campaign->id . ':updated:' . Str::uuid(),
                null,
                $before,
                $campaign->only(array_keys($before))
            );

            return $campaign->refresh();
        });
    }

    public function launchCampaign(AccessRecertificationCampaign $campaign, int $organizationId, User $actor): AccessRecertificationCampaign
    {
        $this->assertCampaignOrganization($campaign, $organizationId);

        if (!in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw new InvalidArgumentException('campaign_cannot_launch');
        }

        return DB::transaction(function () use ($campaign, $organizationId, $actor): AccessRecertificationCampaign {
            $itemsCreated = $this->createItemsFromActiveAssignments($campaign, $organizationId);
            $snapshotHash = hash('sha256', json_encode([
                'campaign_id' => $campaign->id,
                'items_created' => $itemsCreated,
                'scope' => $campaign->scope ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $campaign->forceFill([
                'status' => 'active',
                'starts_at' => $campaign->starts_at ?? now(),
                'launched_by_user_id' => $actor->id,
                'snapshot_hash' => $snapshotHash,
            ])->save();

            $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.campaign.launched',
                'launch',
                'access_recertification_campaign',
                $campaign->id,
                $campaign->name,
                $campaign->correlation_id,
                'arc:campaign:' . $campaign->id . ':launched',
                null,
                [],
                ['status' => 'active', 'items_created' => $itemsCreated, 'snapshot_hash' => $snapshotHash]
            );

            return $campaign->refresh();
        });
    }

    public function completeCampaign(AccessRecertificationCampaign $campaign, int $organizationId, User $actor): AccessRecertificationCampaign
    {
        $this->assertCampaignOrganization($campaign, $organizationId);

        if ($campaign->status !== 'active') {
            throw new InvalidArgumentException('campaign_cannot_complete');
        }

        $openItems = $campaign->items()
            ->whereIn('status', ['pending', 'escalated', 'revoke_requested', 'exception_requested'])
            ->count();

        if ($openItems > 0) {
            throw new InvalidArgumentException('campaign_has_open_items');
        }

        return DB::transaction(function () use ($campaign, $organizationId, $actor): AccessRecertificationCampaign {
            $campaign->forceFill([
                'status' => 'completed',
                'closed_at' => now(),
                'completed_by_user_id' => $actor->id,
            ])->save();

            $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.campaign.completed',
                'complete',
                'access_recertification_campaign',
                $campaign->id,
                $campaign->name,
                $campaign->correlation_id,
                'arc:campaign:' . $campaign->id . ':completed',
                null,
                [],
                ['status' => 'completed']
            );

            return $campaign->refresh();
        });
    }

    public function cancelCampaign(
        AccessRecertificationCampaign $campaign,
        int $organizationId,
        User $actor,
        ?string $reason
    ): AccessRecertificationCampaign {
        $this->assertCampaignOrganization($campaign, $organizationId);

        if (in_array($campaign->status, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('campaign_closed');
        }

        return DB::transaction(function () use ($campaign, $organizationId, $actor, $reason): AccessRecertificationCampaign {
            $campaign->forceFill([
                'status' => 'cancelled',
                'closed_at' => now(),
            ])->save();

            $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.campaign.cancelled',
                'cancel',
                'access_recertification_campaign',
                $campaign->id,
                $campaign->name,
                $campaign->correlation_id,
                'arc:campaign:' . $campaign->id . ':cancelled',
                $reason,
                [],
                ['status' => 'cancelled']
            );

            return $campaign->refresh();
        });
    }

    public function items(AccessRecertificationCampaign $campaign, int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        $this->assertCampaignOrganization($campaign, $organizationId);

        return $campaign->items()
            ->with(['reviewer:id,name', 'subject:id,name', 'latestDecision'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['risk_level'] ?? null, fn (Builder $query, string $riskLevel) => $query->where('risk_level', $riskLevel))
            ->when($filters['reviewer_user_id'] ?? null, fn (Builder $query, int $reviewerId) => $query->where('reviewer_user_id', $reviewerId))
            ->when($filters['subject_user_id'] ?? null, fn (Builder $query, int $subjectId) => $query->where('subject_user_id', $subjectId))
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('due_at')
            ->paginate($perPage);
    }

    public function reviewQueue(int $organizationId, User $actor, array $filters, int $perPage): LengthAwarePaginator
    {
        return AccessRecertificationItem::query()
            ->forOrganization($organizationId)
            ->with(['campaign:id,name,status,due_at', 'reviewer:id,name', 'subject:id,name', 'latestDecision'])
            ->where('reviewer_user_id', $actor->id)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['risk_level'] ?? null, fn (Builder $query, string $riskLevel) => $query->where('risk_level', $riskLevel))
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('due_at')
            ->paginate($perPage);
    }

    public function decide(AccessRecertificationItem $item, int $organizationId, User $actor, array $data): AccessRecertificationDecision
    {
        $this->assertItemOrganization($item, $organizationId);

        if ((int) $item->reviewer_user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('reviewer_required');
        }

        if (!in_array($item->status, ['pending', 'escalated', 'exception_rejected'], true)) {
            throw new InvalidArgumentException('item_already_decided');
        }

        $decisionType = $data['decision'];
        $this->decisionPolicy->assertCanDecide((int) $actor->id, (int) $item->subject_user_id, $decisionType, $data);

        return DB::transaction(function () use ($item, $organizationId, $actor, $data, $decisionType): AccessRecertificationDecision {
            $decision = AccessRecertificationDecision::query()->create([
                'campaign_id' => $item->campaign_id,
                'item_id' => $item->id,
                'organization_id' => $organizationId,
                'reviewer_user_id' => $actor->id,
                'decision' => $decisionType,
                'reason' => $data['reason'],
                'valid_until' => $data['valid_until'] ?? null,
                'next_review_at' => $data['next_review_at'] ?? null,
                'revoke_reason' => $data['revoke_reason'] ?? $data['reason'],
                'revoke_executor_user_id' => $data['revoke_executor_user_id'] ?? null,
                'evidence_notes' => $data['evidence_notes'] ?? null,
                'compensating_controls' => $data['compensating_controls'] ?? [],
                'linked_sod_rule_ids' => $data['linked_sod_rule_ids'] ?? [],
                'evidence_snapshot' => $this->evidence->publicItemEvidence($item->evidence_snapshot ?? []),
            ]);

            $status = match ($decisionType) {
                'approve' => 'approved',
                'revoke' => 'revoke_requested',
                'exception' => 'exception_requested',
            };

            $item->forceFill([
                'status' => $status,
                'decided_at' => now(),
                'next_review_at' => $data['next_review_at'] ?? null,
            ])->save();

            if ($decisionType === 'revoke') {
                $this->createRevocationTask($item, $decision, $data);
            }

            if ($decisionType === 'exception') {
                $this->createExceptionRequest($item, $decision, $data, $actor);
            }

            $audit = $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.decision.' . $this->eventDecisionSuffix($decisionType),
                $decisionType,
                'access_recertification_item',
                $item->id,
                (string) ($item->role_label ?? $item->role_slug),
                $item->correlation_id,
                'arc:decision:' . $decision->id,
                $data['reason'],
                ['status' => 'pending', 'risk' => $item->risk_snapshot],
                ['status' => $status, 'decision_id' => $decision->id],
                [],
                ['campaign_id' => $item->campaign_id, 'evidence' => $this->evidence->publicItemEvidence($item->evidence_snapshot ?? [])],
                $decisionType === 'revoke' ? 'warning' : 'info'
            );

            $decision->forceFill(['audit_event_id' => $audit->id])->save();

            return $decision->refresh()->load(['item', 'reviewer']);
        });
    }

    public function reassign(AccessRecertificationItem $item, int $organizationId, User $actor, int $reviewerUserId, ?string $reason): AccessRecertificationItem
    {
        $this->assertItemOrganization($item, $organizationId);

        if ($reviewerUserId === (int) $item->subject_user_id) {
            throw new InvalidArgumentException('self_review_forbidden');
        }

        return DB::transaction(function () use ($item, $organizationId, $actor, $reviewerUserId, $reason): AccessRecertificationItem {
            $beforeReviewer = $item->reviewer_user_id;
            $item->forceFill([
                'reviewer_user_id' => $reviewerUserId,
                'status' => $item->status === 'escalated' ? 'pending' : $item->status,
            ])->save();

            $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.reviewer.reassigned',
                'reassign',
                'access_recertification_item',
                $item->id,
                (string) ($item->role_label ?? $item->role_slug),
                $item->correlation_id,
                'arc:item:' . $item->id . ':reassign:' . Str::uuid(),
                $reason,
                ['reviewer_user_id' => $beforeReviewer],
                ['reviewer_user_id' => $reviewerUserId]
            );

            return $item->refresh()->load(['reviewer:id,name', 'subject:id,name']);
        });
    }

    public function revocations(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return AccessRecertificationRevocation::query()
            ->forOrganization($organizationId)
            ->with(['campaign:id,name,status', 'item:id,risk_level,status', 'subject:id,name', 'executor:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, string $campaignId) => $query->where('campaign_id', $campaignId))
            ->orderBy('due_at')
            ->paginate($perPage);
    }

    public function completeRevocation(AccessRecertificationRevocation $revocation, int $organizationId, User $actor, array $data): AccessRecertificationRevocation
    {
        $this->assertRevocationOrganization($revocation, $organizationId);

        if ($revocation->status === 'completed') {
            return $revocation->load(['campaign', 'item', 'subject', 'executor']);
        }

        if ($revocation->status !== 'pending') {
            throw new InvalidArgumentException('revocation_cannot_complete');
        }

        return DB::transaction(function () use ($revocation, $organizationId, $actor, $data): AccessRecertificationRevocation {
            $subject = User::query()->findOrFail($revocation->subject_user_id);
            $context = $revocation->role_context_id
                ? AuthorizationContext::query()->find($revocation->role_context_id)
                : null;

            if ($context === null) {
                throw new InvalidArgumentException('role_context_not_found');
            }

            $revoked = $this->authorization->revokeRole($subject, $revocation->role_slug, $context, $actor);

            if (!$revoked) {
                throw new InvalidArgumentException('role_assignment_not_found');
            }

            $revocation->forceFill([
                'status' => 'completed',
                'executor_user_id' => $actor->id,
                'completed_at' => now(),
                'failure_reason' => null,
            ])->save();

            $revocation->item?->forceFill(['status' => 'revoked'])->save();

            $audit = $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.revocation.completed',
                'complete_revocation',
                'access_recertification_revocation',
                $revocation->id,
                $revocation->role_slug,
                $revocation->item?->correlation_id,
                'arc:revocation:' . $revocation->id . ':completed',
                $data['reason'] ?? $revocation->reason,
                ['status' => 'pending'],
                ['status' => 'completed', 'assignment_id' => $revocation->assignment_id],
            );

            $revocation->forceFill(['audit_event_id' => $audit->id])->save();

            return $revocation->refresh()->load(['campaign', 'item', 'subject', 'executor']);
        });
    }

    public function exceptions(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return AccessRecertificationException::query()
            ->forOrganization($organizationId)
            ->with(['campaign:id,name,status', 'item:id,role_label,role_slug,risk_level,status', 'requestedBy:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['campaign_id'] ?? null, fn (Builder $query, string $campaignId) => $query->where('campaign_id', $campaignId))
            ->orderBy('valid_until')
            ->paginate($perPage);
    }

    public function decideException(
        AccessRecertificationException $exception,
        int $organizationId,
        User $actor,
        string $status,
        ?string $reason
    ): AccessRecertificationException {
        $this->assertExceptionOrganization($exception, $organizationId);

        if ($exception->status !== 'requested') {
            throw new InvalidArgumentException('exception_already_decided');
        }

        return DB::transaction(function () use ($exception, $organizationId, $actor, $status, $reason): AccessRecertificationException {
            if ($status === 'approved') {
                $exception->forceFill([
                    'status' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                ])->save();
                $exception->item?->forceFill(['status' => 'exception_approved'])->save();
            } else {
                $exception->forceFill([
                    'status' => 'rejected',
                    'rejected_by_user_id' => $actor->id,
                    'rejected_at' => now(),
                ])->save();
                $exception->item?->forceFill(['status' => 'exception_rejected'])->save();
            }

            $audit = $this->recordAudit(
                $organizationId,
                $actor->id,
                'access_recertification.exception.' . $status,
                $status,
                'access_recertification_exception',
                $exception->id,
                $exception->item?->role_label ?? $exception->item?->role_slug,
                $exception->item?->correlation_id,
                'arc:exception:' . $exception->id . ':' . $status,
                $reason,
                ['status' => 'requested'],
                ['status' => $status]
            );

            $exception->forceFill(['audit_event_id' => $audit->id])->save();

            return $exception->refresh()->load(['campaign', 'item', 'requestedBy', 'approvedBy', 'rejectedBy']);
        });
    }

    public function report(int $organizationId, array $filters): array
    {
        $campaignId = $filters['campaign_id'] ?? null;
        $items = AccessRecertificationItem::query()
            ->forOrganization($organizationId)
            ->when($campaignId, fn (Builder $query, string $id) => $query->where('campaign_id', $id));

        $exceptions = AccessRecertificationException::query()
            ->forOrganization($organizationId)
            ->when($campaignId, fn (Builder $query, string $id) => $query->where('campaign_id', $id));

        return [
            'summary' => [
                'items_total' => (clone $items)->count(),
                'pending' => (clone $items)->whereIn('status', ['pending', 'escalated'])->count(),
                'approved' => (clone $items)->where('status', 'approved')->count(),
                'revoked' => (clone $items)->where('status', 'revoked')->count(),
                'revoke_requested' => (clone $items)->where('status', 'revoke_requested')->count(),
                'exceptions_active' => (clone $exceptions)->where('status', 'approved')->where('valid_until', '>=', now())->count(),
                'exceptions_requested' => (clone $exceptions)->where('status', 'requested')->count(),
                'overdue' => (clone $items)->whereIn('status', ['pending', 'escalated'])->where('due_at', '<', now())->count(),
                'dangerous_access' => (clone $items)->whereIn('risk_level', ['high', 'critical'])->count(),
            ],
            'by_status' => $this->groupCounts(clone $items, 'status'),
            'by_risk' => $this->groupCounts(clone $items, 'risk_level'),
            'overdue' => $this->overdueRows($organizationId, $campaignId),
            'dangerous_access' => $this->dangerousRows($organizationId, $campaignId),
            'exceptions' => $this->exceptionRows($organizationId, $campaignId),
        ];
    }

    public function exportEvidence(int $organizationId, User $actor, array $filters): StreamedResponse
    {
        $campaignId = $filters['campaign_id'] ?? null;
        $rows = $this->exportRows($organizationId, $campaignId);

        $export = AccessRecertificationExport::query()->create([
            'organization_id' => $organizationId,
            'campaign_id' => $campaignId,
            'requested_by_user_id' => $actor->id,
            'status' => 'completed',
            'format' => 'csv',
            'filters' => $filters,
            'row_count' => count($rows),
            'completed_at' => now(),
        ]);

        $audit = $this->recordAudit(
            $organizationId,
            $actor->id,
            'access_recertification.report.exported',
            'export',
            'access_recertification_export',
            $export->id,
            'Evidence export',
            $campaignId,
            'arc:export:' . $export->id,
            null,
            [],
            ['row_count' => count($rows), 'filters' => $filters],
            [],
            ['redaction_policy' => 'public_evidence_only']
        );

        $export->forceFill(['audit_event_id' => $audit->id])->save();

        return response()->streamDownload(static function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Кампания',
                'Статус кампании',
                'ID пользователя',
                'Роль',
                'Контекст',
                'Проверяющий',
                'Статус проверки',
                'Риск',
                'Решение',
                'Причина',
                'Срок исключения',
                'Дата решения',
                'Correlation ID',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        }, 'access-recertification-evidence-' . now()->format('Y-m-d-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function assertCampaignOrganization(AccessRecertificationCampaign $campaign, int $organizationId): void
    {
        if ((int) $campaign->organization_id !== $organizationId) {
            throw new InvalidArgumentException('campaign_not_found');
        }
    }

    private function createItemsFromActiveAssignments(AccessRecertificationCampaign $campaign, int $organizationId): int
    {
        $scope = $campaign->scope ?? [];
        $created = 0;

        $assignments = UserRoleAssignment::query()
            ->active()
            ->with(['user:id,name,email', 'context.parentContext'])
            ->where(function (Builder $query) use ($organizationId): void {
                $query->whereHas('context', function (Builder $contextQuery) use ($organizationId): void {
                    $contextQuery->where(function (Builder $inner) use ($organizationId): void {
                        $inner->where('type', AuthorizationContext::TYPE_ORGANIZATION)
                            ->where('resource_id', $organizationId);
                    })->orWhere(function (Builder $inner) use ($organizationId): void {
                        $inner->where('type', AuthorizationContext::TYPE_PROJECT)
                            ->whereHas('parentContext', function (Builder $parent) use ($organizationId): void {
                                $parent->where('type', AuthorizationContext::TYPE_ORGANIZATION)
                                    ->where('resource_id', $organizationId);
                            });
                    });
                })->orWhere(function (Builder $systemQuery) use ($organizationId): void {
                    $systemQuery->where('role_type', UserRoleAssignment::TYPE_SYSTEM)
                        ->whereHas('context', function (Builder $contextQuery): void {
                            $contextQuery->where('type', AuthorizationContext::TYPE_SYSTEM);
                        })
                        ->whereHas('user.organizations', function (Builder $organizationQuery) use ($organizationId): void {
                            $organizationQuery
                                ->where('organizations.id', $organizationId)
                                ->where('organization_user.is_active', true);
                        });
                });
            })
            ->when($scope['role_slugs'] ?? null, fn (Builder $query, array $roles) => $query->whereIn('role_slug', $roles))
            ->when($scope['user_ids'] ?? null, fn (Builder $query, array $userIds) => $query->whereIn('user_id', $userIds))
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            $permissions = $this->rolePermissions((string) $assignment->role_slug, (string) $assignment->role_type, $organizationId);
            $role = $this->roles->findBySlug((string) $assignment->role_slug, $organizationId) ?? [];
            $risk = $this->riskScanner->scan((string) $assignment->role_slug, $permissions);

            if (!$this->campaignAllowsRisk($campaign, $risk['level'])) {
                continue;
            }

            $context = $assignment->context;
            $reviewerId = (int) $campaign->owner_user_id;
            $status = 'pending';

            if ($reviewerId === (int) $assignment->user_id) {
                $reviewerId = $campaign->escalation_user_id !== null ? (int) $campaign->escalation_user_id : 0;
                $status = $reviewerId > 0 ? 'pending' : 'escalated';
            }

            $snapshot = $this->evidence->assignmentSnapshot([
                'assignment_id' => $assignment->id,
                'user_id' => $assignment->user_id,
                'user_name' => $assignment->user?->name,
                'user_email' => $assignment->user?->email,
                'role_slug' => $assignment->role_slug,
                'role_type' => $assignment->role_type,
                'role_label' => $role['name'] ?? $assignment->role_slug,
                'context_type' => $context?->type,
                'context_resource_id' => $context?->resource_id,
                'permissions' => $permissions,
                'risk' => $risk,
            ]);
            $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $item = AccessRecertificationItem::query()->firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'assignment_snapshot_hash' => $snapshotHash,
                ],
                [
                    'organization_id' => $organizationId,
                    'reviewer_user_id' => $reviewerId > 0 ? $reviewerId : null,
                    'subject_user_id' => $assignment->user_id,
                    'assignment_id' => $assignment->id,
                    'role_slug' => $assignment->role_slug,
                    'role_type' => $assignment->role_type,
                    'role_context_id' => $context?->id,
                    'role_context_type' => $context?->type,
                    'role_context_resource_id' => $context?->resource_id,
                    'role_context_label' => $this->contextLabel($context),
                    'role_label' => $role['name'] ?? $assignment->role_slug,
                    'permission_snapshot' => $permissions,
                    'risk_snapshot' => $risk,
                    'evidence_snapshot' => $snapshot,
                    'risk_level' => $risk['level'],
                    'status' => $status,
                    'due_at' => $campaign->due_at,
                    'correlation_id' => $campaign->correlation_id . ':item:' . $assignment->id,
                ]
            );

            if ($item->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function rolePermissions(string $roleSlug, string $roleType, int $organizationId): array
    {
        $role = $this->roles->findBySlug($roleSlug, $organizationId);

        if ($role === null) {
            return [];
        }

        $permissions = $role['system_permissions'] ?? [];

        foreach (($role['module_permissions'] ?? []) as $module => $modulePermissions) {
            if (!is_array($modulePermissions)) {
                continue;
            }

            foreach ($modulePermissions as $permission) {
                if (!is_string($permission)) {
                    continue;
                }

                if ($permission === '*') {
                    $permissions[] = $module . '.*';
                } elseif (str_contains($permission, '.')) {
                    $permissions[] = $permission;
                } else {
                    $permissions[] = $module . '.' . $permission;
                }
            }
        }

        return array_values(array_unique($permissions));
    }

    private function createRevocationTask(AccessRecertificationItem $item, AccessRecertificationDecision $decision, array $data): void
    {
        AccessRecertificationRevocation::query()->firstOrCreate(
            ['item_id' => $item->id],
            [
                'campaign_id' => $item->campaign_id,
                'organization_id' => $item->organization_id,
                'assignment_id' => $item->assignment_id,
                'subject_user_id' => $item->subject_user_id,
                'role_slug' => $item->role_slug,
                'role_type' => $item->role_type,
                'role_context_id' => $item->role_context_id,
                'status' => 'pending',
                'reason' => $data['revoke_reason'] ?? $decision->reason,
                'executor_user_id' => $data['revoke_executor_user_id'],
                'due_at' => Carbon::parse($item->due_at ?? now())->addDays(3),
            ]
        );
    }

    private function createExceptionRequest(
        AccessRecertificationItem $item,
        AccessRecertificationDecision $decision,
        array $data,
        User $actor
    ): void {
        AccessRecertificationException::query()->create([
            'campaign_id' => $item->campaign_id,
            'item_id' => $item->id,
            'decision_id' => $decision->id,
            'organization_id' => $item->organization_id,
            'status' => 'requested',
            'requested_by_user_id' => $actor->id,
            'reason' => $decision->reason,
            'valid_until' => $data['valid_until'],
            'compensating_controls' => $data['compensating_controls'] ?? [],
            'linked_sod_rule_ids' => $data['linked_sod_rule_ids'] ?? [],
            'evidence_snapshot' => $decision->evidence_snapshot,
        ]);
    }

    private function groupCounts(Builder $query, string $column): array
    {
        return $query->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->pluck('total', $column)
            ->toArray();
    }

    private function overdueRows(int $organizationId, ?string $campaignId): array
    {
        return AccessRecertificationItem::query()
            ->forOrganization($organizationId)
            ->with(['campaign:id,name', 'reviewer:id,name', 'subject:id,name'])
            ->when($campaignId, fn (Builder $query, string $id) => $query->where('campaign_id', $id))
            ->whereIn('status', ['pending', 'escalated'])
            ->where('due_at', '<', now())
            ->orderBy('due_at')
            ->limit(50)
            ->get()
            ->map(fn (AccessRecertificationItem $item): array => $this->itemReportRow($item))
            ->all();
    }

    private function dangerousRows(int $organizationId, ?string $campaignId): array
    {
        return AccessRecertificationItem::query()
            ->forOrganization($organizationId)
            ->with(['campaign:id,name', 'reviewer:id,name', 'subject:id,name'])
            ->when($campaignId, fn (Builder $query, string $id) => $query->where('campaign_id', $id))
            ->whereIn('risk_level', ['high', 'critical'])
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 ELSE 2 END")
            ->limit(50)
            ->get()
            ->map(fn (AccessRecertificationItem $item): array => $this->itemReportRow($item))
            ->all();
    }

    private function exceptionRows(int $organizationId, ?string $campaignId): array
    {
        return AccessRecertificationException::query()
            ->forOrganization($organizationId)
            ->with(['campaign:id,name', 'item:id,role_label,role_slug,risk_level,status'])
            ->when($campaignId, fn (Builder $query, string $id) => $query->where('campaign_id', $id))
            ->whereIn('status', ['requested', 'approved'])
            ->orderBy('valid_until')
            ->limit(50)
            ->get()
            ->map(fn (AccessRecertificationException $exception): array => [
                'id' => $exception->id,
                'campaign_id' => $exception->campaign_id,
                'campaign_name' => $exception->campaign?->name,
                'item_id' => $exception->item_id,
                'role_label' => $exception->item?->role_label ?? $exception->item?->role_slug,
                'risk_level' => $exception->item?->risk_level,
                'status' => $exception->status,
                'valid_until' => $exception->valid_until?->toISOString(),
            ])
            ->all();
    }

    private function exportRows(int $organizationId, ?string $campaignId): array
    {
        return AccessRecertificationItem::query()
            ->forOrganization($organizationId)
            ->with(['campaign:id,name,status', 'reviewer:id,name', 'latestDecision'])
            ->when($campaignId, fn (Builder $query, string $id) => $query->where('campaign_id', $id))
            ->orderBy('campaign_id')
            ->orderBy('subject_user_id')
            ->get()
            ->map(function (AccessRecertificationItem $item): array {
                $decision = $item->latestDecision;

                return [
                    $item->campaign?->name,
                    $item->campaign?->status,
                    $item->subject_user_id,
                    $item->role_label ?? $item->role_slug,
                    $item->role_context_label ?? $item->role_context_type,
                    $item->reviewer?->name,
                    $item->status,
                    $item->risk_level,
                    $decision?->decision,
                    $decision?->reason,
                    $decision?->valid_until?->format('d.m.Y'),
                    $decision?->created_at?->format('d.m.Y H:i:s'),
                    $item->correlation_id,
                ];
            })
            ->all();
    }

    private function itemReportRow(AccessRecertificationItem $item): array
    {
        return [
            'id' => $item->id,
            'campaign_id' => $item->campaign_id,
            'campaign_name' => $item->campaign?->name,
            'subject_user_id' => $item->subject_user_id,
            'subject_name' => $item->subject?->name,
            'reviewer_user_id' => $item->reviewer_user_id,
            'reviewer_name' => $item->reviewer?->name,
            'role_label' => $item->role_label ?? $item->role_slug,
            'status' => $item->status,
            'risk_level' => $item->risk_level,
            'due_at' => $item->due_at?->toISOString(),
        ];
    }

    private function campaignAllowsRisk(AccessRecertificationCampaign $campaign, string $riskLevel): bool
    {
        if ($campaign->risk_mode === 'all') {
            return true;
        }

        if ($campaign->risk_mode === 'high_risk_only') {
            return in_array($riskLevel, ['high', 'critical'], true);
        }

        $scope = $campaign->scope ?? [];
        $levels = $scope['risk_levels'] ?? null;

        if (!is_array($levels) || $levels === []) {
            $levels = ['medium', 'high', 'critical'];
        }

        return in_array($riskLevel, $levels, true);
    }

    private function contextLabel(?AuthorizationContext $context): ?string
    {
        if ($context === null) {
            return null;
        }

        return $context->type . ($context->resource_id !== null ? ':' . $context->resource_id : '');
    }

    private function eventDecisionSuffix(string $decision): string
    {
        return match ($decision) {
            'approve' => 'approved',
            'revoke' => 'revoke_requested',
            'exception' => 'exception_requested',
            default => $decision,
        };
    }

    private function assertItemOrganization(AccessRecertificationItem $item, int $organizationId): void
    {
        if ((int) $item->organization_id !== $organizationId) {
            throw new InvalidArgumentException('item_not_found');
        }
    }

    private function assertRevocationOrganization(AccessRecertificationRevocation $revocation, int $organizationId): void
    {
        if ((int) $revocation->organization_id !== $organizationId) {
            throw new InvalidArgumentException('revocation_not_found');
        }
    }

    private function assertExceptionOrganization(AccessRecertificationException $exception, int $organizationId): void
    {
        if ((int) $exception->organization_id !== $organizationId) {
            throw new InvalidArgumentException('exception_not_found');
        }
    }

    private function recordAudit(
        int $organizationId,
        ?int $actorUserId,
        string $eventType,
        string $action,
        string $subjectType,
        int|string $subjectId,
        ?string $subjectLabel,
        ?string $correlationId,
        string $sourceEventId,
        ?string $reason = null,
        array $beforeState = [],
        array $afterState = [],
        array $diff = [],
        array $domainContext = [],
        string $severity = 'info',
    ): ImmutableAuditEvent {
        return $this->audit->record($this->evidence->auditEventData(
            organizationId: $organizationId,
            actorUserId: $actorUserId,
            eventType: $eventType,
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
            correlationId: $correlationId,
            sourceEventId: $sourceEventId,
            reason: $reason,
            beforeState: $beforeState,
            afterState: $afterState,
            diff: $diff,
            domainContext: $domainContext,
            severity: $severity,
        ));
    }
}
