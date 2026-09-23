<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Services;

use App\BusinessModules\Core\Reporting\Support\ExactDecimal;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeApproval;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfiHistory;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Models\VariationOrder;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeWorkflowEventRecorder;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ContractProjectAllocation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\ProjectParticipantService;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ChangeManagementService
{
    public function __construct(
        private readonly ChangeWorkflowEventRecorder $changeEvents,
        private readonly ProjectParticipantService $participants,
        private readonly FileService $files,
        private readonly AuthorizationService $authorization,
    ) {}

    public function paginateRfis(int $organizationId, int $perPage, array $filters = [], bool $customer = false, ?int $actorUserId = null): LengthAwarePaginator
    {
        if ($actorUserId !== null) {
            $this->assertPermission($actorUserId, $organizationId, 'change-management.view');
        }
        if (isset($filters['project_id'])) {
            $this->assertActiveParticipant((int) $filters['project_id'], $organizationId);
        }
        $manageAnyProjectRfi = ! $customer
            && $actorUserId !== null
            && isset($filters['project_id'])
            && $this->hasPermission($actorUserId, $organizationId, 'change-management.rfi.reassign');
        if ($manageAnyProjectRfi) {
            $this->assertActiveParticipant((int) $filters['project_id'], $organizationId);
        }
        $query = ChangeManagementRfi::query()
            ->when($filters['project_id'] ?? null, static fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, static fn ($query, $status) => $query->where('status', $status))
            ->where(function ($scope) use ($organizationId): void {
                $scope->whereHas('project', static fn ($project) => $project->where('organization_id', $organizationId))
                    ->orWhereExists(static function ($participant) use ($organizationId): void {
                        $participant->selectRaw('1')
                            ->from('project_organization')
                            ->whereColumn('project_organization.project_id', 'change_management_rfis.project_id')
                            ->where('project_organization.organization_id', $organizationId)
                            ->where('project_organization.is_active', true);
                    });
            });

        if ($customer) {
            $query->where(function ($scope) use ($organizationId): void {
                $scope->where(function ($builder) use ($organizationId): void {
                    $builder->where('organization_id', $organizationId)
                        ->where(function ($authorRows): void {
                            $authorRows->whereNotNull('recipient_organization_id')
                                ->orWhere(function ($drafts): void {
                                    $drafts->where('status', 'draft')
                                        ->whereHas('history', static fn ($history) => $history->where('event', 'created'));
                                });
                        });
                })->orWhere(function ($builder) use ($organizationId): void {
                    $builder->where('recipient_organization_id', $organizationId);
                });
            });
        } elseif (! $manageAnyProjectRfi) {
            $query->where(function ($builder) use ($organizationId): void {
                $builder->where('organization_id', $organizationId)
                    ->orWhere('recipient_organization_id', $organizationId);
            });
        }

        if ($customer) {
            $query->when(($filters['direction'] ?? null) === 'incoming', static fn ($builder) => $builder->where('recipient_organization_id', $organizationId));
            $query->when(($filters['direction'] ?? null) === 'outgoing', static fn ($builder) => $builder->where('organization_id', $organizationId));
            $query->when(($filters['direction'] ?? null) === 'drafts', static fn ($builder) => $builder->where('organization_id', $organizationId)->where('status', 'draft'));
        }

        return $query->latest('id')->paginate($perPage);
    }

    public function paginateChanges(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ChangeRequest::query()
            ->with(['impact', 'approvals', 'variationOrders', 'relatedRfi'])
            ->forOrganization($organizationId)
            ->when($filters['project_id'] ?? null, static fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, static fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    public function paginateClaims(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return ChangeClaim::query()
            ->with(['changeRequest'])
            ->forOrganization($organizationId)
            ->when($filters['project_id'] ?? null, static fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['status'] ?? null, static fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage);
    }

    public function createRfi(int $organizationId, int $userId, array $data): ChangeManagementRfi
    {
        $projectId = (int) $data['project_id'];
        $this->assertPermission($userId, $organizationId, 'change-management.rfi.create');
        $this->assertActiveParticipant($projectId, $organizationId);
        if (! empty($data['recipient_organization_id'])) {
            $this->assertActiveParticipant($projectId, (int) $data['recipient_organization_id']);
            if ($this->participants->isConfigured($projectId)) {
                $this->assertDirectNeighbor($projectId, $organizationId, (int) $data['recipient_organization_id']);
            }
        }

        return DB::transaction(function () use ($organizationId, $userId, $data, $projectId): ChangeManagementRfi {
            $rfi = ChangeManagementRfi::create([
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'recipient_organization_id' => $data['recipient_organization_id'] ?? null,
                'created_by_user_id' => $userId,
                'rfi_number' => $data['rfi_number'] ?? $this->nextNumber(ChangeManagementRfi::class, $organizationId, 'RFI', 'rfi_number'),
                'subject' => $data['subject'],
                'question' => $data['question'],
                'addressee_type' => $data['addressee_type'] ?? 'organization',
                'status' => 'draft',
                'response_due_date' => $data['due_date'] ?? $data['response_due_date'] ?? null,
                'attachments' => [],
                'metadata' => $data['metadata'] ?? [],
            ]);
            $this->recordRfiEvent($rfi, $organizationId, $userId, 'created', null, 'draft');

            return $rfi->load('history.actorOrganization');
        });
    }

    public function findRfi(int $organizationId, int $id): ChangeManagementRfi
    {
        $rfi = ChangeManagementRfi::query()
            ->forOrganization($organizationId)
            ->find($id);

        if (! $rfi instanceof ChangeManagementRfi) {
            throw new DomainException(trans_message('change_management.errors.rfi_not_found'));
        }

        return $rfi;
    }

    public function findVisibleRfi(int $organizationId, int $id, int $actorUserId, bool $customer = false): ChangeManagementRfi
    {
        $this->assertPermission($actorUserId, $organizationId, 'change-management.view');
        $rfi = ChangeManagementRfi::query()->with(['history.actorOrganization'])->find($id);

        if (! $rfi instanceof ChangeManagementRfi) {
            throw new DomainException(trans_message('change_management.errors.rfi_not_found'));
        }

        $isNewDraft = $rfi->status === 'draft'
            && $organizationId === (int) $rfi->organization_id
            && $rfi->history->contains(static fn (ChangeManagementRfiHistory $event): bool => $event->event === 'created');
        $isParty = $organizationId === (int) $rfi->organization_id
            || $organizationId === (int) $rfi->recipient_organization_id;
        $canManageProject = ! $customer && $this->hasPermission($actorUserId, $organizationId, 'change-management.rfi.reassign');
        if ((! $isParty && ! $canManageProject)
            || ($customer && $rfi->recipient_organization_id === null && ! $isNewDraft)) {
            throw new DomainException(trans_message('change_management.errors.rfi_not_found'));
        }

        $this->assertActiveParticipant((int) $rfi->project_id, $organizationId);

        return $rfi;
    }

    public function rfiRecipients(int $projectId, int $organizationId, int $actorUserId): array
    {
        $this->assertPermission($actorUserId, $organizationId, 'change-management.view');
        $this->assertActiveParticipant($projectId, $organizationId);
        if (! $this->participants->isConfigured($projectId)) {
            return [];
        }

        $parentId = $this->participants->getParent($projectId, $organizationId);
        $ids = array_values(array_unique(array_filter([
            $parentId,
            ...$this->participants->getChildren($projectId, $organizationId),
        ], static fn ($id): bool => $id !== null && (int) $id !== $organizationId)));

        return Organization::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(static function (Organization $organization) use ($parentId): array {
                return [
                    'organization_id' => (int) $organization->id,
                    'name' => $organization->name,
                    'relation' => (int) $organization->id === (int) $parentId ? 'parent' : 'child',
                ];
            })->all();
    }

    public function sendRfi(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId, ?int $recipientOrganizationId = null): ChangeManagementRfi
    {
        $this->assertPermission($actorUserId, $actorOrganizationId, 'change-management.rfi.create');

        return DB::transaction(function () use ($rfi, $actorOrganizationId, $actorUserId, $recipientOrganizationId): ChangeManagementRfi {
            Project::query()->whereKey($rfi->project_id)->lockForUpdate()->firstOrFail();
            $locked = ChangeManagementRfi::query()->whereKey($rfi->id)->lockForUpdate()->firstOrFail();
            $this->assertRfiRole($locked, $actorOrganizationId, 'author');
            $this->assertTransitionStatus($locked->status, ['draft']);
            $recipientId = $recipientOrganizationId ?? (int) $locked->recipient_organization_id;
            if (! $recipientId) {
                throw new DomainException(trans_message('change_management.errors.rfi_recipient_required'));
            }
            $this->assertDirectNeighbor((int) $locked->project_id, $actorOrganizationId, $recipientId);
            $this->assertActiveParticipant((int) $locked->project_id, $recipientId);
            $oldStatus = $locked->status;
            $locked->forceFill(['recipient_organization_id' => $recipientId, 'status' => 'sent', 'sent_at' => now()])->save();
            $this->recordRfiEvent($locked, $actorOrganizationId, $actorUserId, 'sent', $oldStatus, 'sent');
            $this->notifyRfi($locked, $recipientId, $actorUserId, 'sent');

            return $locked->refresh()->load('history.actorOrganization');
        });
    }

    public function answerRfi(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId, string $answer): ChangeManagementRfi
    {
        return $this->rfiTransition($rfi, $actorOrganizationId, $actorUserId, 'recipient', ['sent', 'overdue', 'clarification_requested'], 'answered', 'answered', $answer);
    }

    public function acceptRfi(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId): ChangeManagementRfi
    {
        return $this->rfiTransition($rfi, $actorOrganizationId, $actorUserId, 'author', ['answered'], 'accepted', 'accepted');
    }

    public function requestRfiClarification(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId, string $message): ChangeManagementRfi
    {
        return $this->rfiTransition($rfi, $actorOrganizationId, $actorUserId, 'author', ['answered'], 'clarification_requested', 'clarification_requested', $message);
    }

    public function closeRfi(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId): ChangeManagementRfi
    {
        return $this->rfiTransition($rfi, $actorOrganizationId, $actorUserId, 'author', ['accepted'], 'closed', 'closed');
    }

    public function reassignRfi(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId, int $recipientOrganizationId, string $reason): ChangeManagementRfi
    {
        $this->assertPermission($actorUserId, $actorOrganizationId, 'change-management.rfi.reassign');
        if (! trim($reason)) {
            throw new DomainException(trans_message('change_management.errors.rfi_reassign_reason_required'));
        }

        return DB::transaction(function () use ($rfi, $actorOrganizationId, $actorUserId, $recipientOrganizationId, $reason): ChangeManagementRfi {
            Project::query()->whereKey($rfi->project_id)->lockForUpdate()->firstOrFail();
            $locked = ChangeManagementRfi::query()->whereKey($rfi->id)->lockForUpdate()->firstOrFail();
            $this->assertActorParticipant((int) $locked->project_id, $actorOrganizationId);
            $this->assertTransitionStatus($locked->status, ['draft', 'sent', 'answered', 'clarification_requested', 'overdue']);
            $this->assertActiveParticipant((int) $locked->project_id, $recipientOrganizationId);
            $this->assertDirectNeighbor((int) $locked->project_id, (int) $locked->organization_id, $recipientOrganizationId);
            $previous = $locked->recipient_organization_id;
            $locked->forceFill(['recipient_organization_id' => $recipientOrganizationId])->save();
            $this->recordRfiEvent($locked, $actorOrganizationId, $actorUserId, 'reassigned', $locked->status, $locked->status, $reason, [
                'from_organization_id' => $previous,
                'to_organization_id' => $recipientOrganizationId,
            ]);
            $this->notifyRfi($locked, $recipientOrganizationId, $actorUserId, 'reassigned');

            return $locked->refresh()->load('history.actorOrganization');
        });
    }

    public function attachRfiFile(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId, UploadedFile $file): ChangeManagementRfi
    {
        $this->assertPermission($actorUserId, $actorOrganizationId, 'change-management.rfi.attach');
        if ((int) $file->getSize() > 20 * 1024 * 1024 || ! in_array($file->getMimeType(), [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/png',
            'image/jpeg',
        ], true)) {
            throw new DomainException(trans_message('change_management.errors.rfi_attachment_type_or_size_invalid'));
        }
        $this->assertUploadAllowed($rfi, $actorOrganizationId);
        $organization = Organization::query()->findOrFail($actorOrganizationId);
        $attachmentId = (string) Str::uuid();
        $path = $this->files->upload($file, "change-management/rfis/{$rfi->id}", null, 'private', $organization, false, true);
        if (! is_string($path) || ! str_starts_with($path, "org-{$actorOrganizationId}/change-management/rfis/{$rfi->id}/")) {
            throw new DomainException(trans_message('change_management.errors.rfi_attachment_upload_failed'));
        }
        $attachment = [
            'id' => $attachmentId,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'path' => $path,
            'uploaded_by_organization_id' => $actorOrganizationId,
        ];

        try {
            return DB::transaction(function () use ($rfi, $actorOrganizationId, $actorUserId, $attachment): ChangeManagementRfi {
                $locked = ChangeManagementRfi::query()->whereKey($rfi->id)->lockForUpdate()->firstOrFail();
                $this->assertUploadAllowed($locked, $actorOrganizationId);
                $attachments = $locked->attachments ?? [];
                $attachments[] = $attachment;
                $locked->forceFill(['attachments' => $attachments])->save();
                $this->recordRfiEvent($locked, $actorOrganizationId, $actorUserId, 'attachment_added', $locked->status, $locked->status, null, [], [$attachment]);

                return $locked->refresh()->load('history.actorOrganization');
            });
        } catch (\Throwable $exception) {
            try {
                $this->files->delete($path, $organization);
            } catch (\Throwable $cleanupException) {
                Log::warning('change_management.rfi_attachment_cleanup_failed', [
                    'rfi_id' => $rfi->id,
                    'organization_id' => $actorOrganizationId,
                    'exception' => $cleanupException::class,
                ]);
            }
            Log::warning('change_management.rfi_attachment_db_failed', [
                'rfi_id' => $rfi->id,
                'organization_id' => $actorOrganizationId,
                'user_id' => $actorUserId,
                'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }

    public function rfiAttachmentUrl(ChangeManagementRfi $rfi, int $actorOrganizationId, int $actorUserId, string $attachmentId): ?string
    {
        $this->assertPermission($actorUserId, $actorOrganizationId, 'change-management.view');
        $this->assertActorParticipant((int) $rfi->project_id, $actorOrganizationId);
        if ($actorOrganizationId !== (int) $rfi->organization_id
            && $actorOrganizationId !== (int) $rfi->recipient_organization_id) {
            throw new DomainException(trans_message('change_management.errors.rfi_forbidden'));
        }
        $attachment = collect($rfi->attachments ?? [])->firstWhere('id', $attachmentId);
        if (! is_array($attachment)) {
            $attachment = $rfi->history->flatMap(static fn ($event) => $event->attachments ?? [])
                ->firstWhere('id', $attachmentId);
        }
        if (! is_array($attachment)) {
            throw new DomainException(trans_message('change_management.errors.rfi_attachment_not_found'));
        }
        $ownerId = (int) ($attachment['uploaded_by_organization_id'] ?? 0);
        $path = $attachment['path'] ?? null;
        if ($ownerId < 1 || ! is_string($path)
            || ! str_starts_with($path, "org-{$ownerId}/change-management/rfis/{$rfi->id}/")) {
            throw new DomainException(trans_message('change_management.errors.rfi_attachment_not_found'));
        }
        $organization = Organization::query()->find($ownerId);
        if (! $organization instanceof Organization) {
            throw new DomainException(trans_message('change_management.errors.rfi_attachment_not_found'));
        }
        $filename = str_replace(['"', "\r", "\n"], '', (string) ($attachment['name'] ?? 'attachment'));

        return $this->files->temporaryUrl($path, 5, $organization, [
            'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function assertCanDeactivateRfiParticipant(int $projectId, int $organizationId): void
    {
        $hasOpenRfi = ChangeManagementRfi::query()
            ->where('project_id', $projectId)
            ->where(function ($query) use ($organizationId): void {
                $query->where('recipient_organization_id', $organizationId)
                    ->orWhere('organization_id', $organizationId);
            })
            ->whereIn('status', ['sent', 'answered', 'accepted', 'clarification_requested', 'overdue'])
            ->exists();
        if ($hasOpenRfi) {
            throw new DomainException(trans_message('change_management.errors.rfi_reassign_before_deactivation'));
        }
    }

    private function rfiTransition(
        ChangeManagementRfi $rfi,
        int $actorOrganizationId,
        int $actorUserId,
        string $party,
        array $allowedStatuses,
        string $status,
        string $event,
        ?string $message = null,
    ): ChangeManagementRfi {
        return DB::transaction(function () use ($rfi, $actorOrganizationId, $actorUserId, $party, $allowedStatuses, $status, $event, $message): ChangeManagementRfi {
            $permission = $status === 'answered' ? 'change-management.rfi.answer' : 'change-management.rfi.review';
            $this->assertPermission($actorUserId, $actorOrganizationId, $permission);
            $locked = ChangeManagementRfi::query()->whereKey($rfi->id)->lockForUpdate()->firstOrFail();
            $this->assertRfiRole($locked, $actorOrganizationId, $party);
            $this->assertTransitionStatus($locked->status, $allowedStatuses);
            $from = $locked->status;
            $attributes = ['status' => $status];
            if ($status === 'answered') {
                $attributes['answer'] = $message;
                $attributes['answered_at'] = now();
            } elseif ($status === 'accepted') {
                $attributes['accepted_at'] = now();
            } elseif ($status === 'closed') {
                $attributes['closed_at'] = now();
            }
            $locked->forceFill($attributes)->save();
            $this->recordRfiEvent($locked, $actorOrganizationId, $actorUserId, $event, $from, $status, $message);
            $targetOrganizationId = $status === 'answered' ? (int) $locked->organization_id : (int) $locked->recipient_organization_id;
            if ($targetOrganizationId > 0) {
                $this->notifyRfi($locked, $targetOrganizationId, $actorUserId, $event);
            }

            return $locked->refresh()->load('history.actorOrganization');
        });
    }

    private function recordRfiEvent(
        ChangeManagementRfi $rfi,
        int $actorOrganizationId,
        int $actorUserId,
        string $event,
        ?string $from,
        ?string $to,
        ?string $message = null,
        array $metadata = [],
        array $attachments = [],
    ): void {
        ChangeManagementRfiHistory::query()->create([
            'rfi_id' => $rfi->id,
            'actor_user_id' => $actorUserId,
            'actor_organization_id' => $actorOrganizationId,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'message' => $message,
            'attachments' => $attachments,
            'metadata' => $metadata,
        ]);
    }

    private function notifyRfi(ChangeManagementRfi $rfi, int $targetOrganizationId, int $actorUserId, string $event): void
    {
        DB::afterCommit(function () use ($rfi, $targetOrganizationId, $actorUserId, $event): void {
            try {
                $organization = Organization::query()->find($targetOrganizationId);
                if (! $organization instanceof Organization) {
                    return;
                }
                $this->assertActorParticipant((int) $rfi->project_id, $targetOrganizationId);
                $notificationService = app(NotificationService::class);
                $users = $organization->users()->wherePivot('is_active', true)->get();
                foreach ($users as $user) {
                    if ((int) $user->id === $actorUserId) {
                        continue;
                    }
                    if (! $this->hasPermission((int) $user->id, $targetOrganizationId, 'change-management.view')) {
                        continue;
                    }
                    $customerInterface = $user->canAccessInterface('customer');
                    if (! $customerInterface && ! $user->canAccessInterface('admin')) {
                        continue;
                    }
                    $targetRoute = $customerInterface
                        ? '/dashboard/rfi?project_id='.$rfi->project_id.'&id='.$rfi->id
                        : '/change-management?tab=rfis&id='.$rfi->id;
                    $notificationService->send(
                        $user,
                        'change_management.rfi.'.$event,
                        [
                            'title' => trans_message('change_management.notifications.title'),
                            'message' => trans_message('change_management.notifications.'.$event),
                            'category' => 'change_management',
                            'interface' => $customerInterface ? 'customer' : 'admin',
                            'rfi_id' => $rfi->id,
                            'project_id' => $rfi->project_id,
                            'entity' => ['type' => 'rfi', 'id' => $rfi->id],
                            'target_route' => $targetRoute,
                        ],
                        'change_management',
                        'normal',
                        ['in_app'],
                        $targetOrganizationId,
                        interfaces: [$customerInterface ? 'customer' : 'admin'],
                    );
                }
            } catch (\Throwable $exception) {
                Log::warning('change_management.rfi_notification_failed', [
                    'rfi_id' => $rfi->id,
                    'organization_id' => $targetOrganizationId,
                    'event' => $event,
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    private function assertRfiRole(ChangeManagementRfi $rfi, int $organizationId, string $party): void
    {
        $this->assertActorParticipant((int) $rfi->project_id, $organizationId);
        $expectedId = $party === 'author' ? (int) $rfi->organization_id : (int) $rfi->recipient_organization_id;
        if ($expectedId < 1 || $organizationId !== $expectedId) {
            throw new DomainException(trans_message('change_management.errors.rfi_forbidden'));
        }
    }

    private function assertUploadAllowed(ChangeManagementRfi $rfi, int $organizationId): void
    {
        if ($organizationId === (int) $rfi->organization_id) {
            $this->assertRfiRole($rfi, $organizationId, 'author');
            $this->assertTransitionStatus($rfi->status, ['draft', 'answered']);

            return;
        }
        $this->assertRfiRole($rfi, $organizationId, 'recipient');
        $this->assertTransitionStatus($rfi->status, ['sent', 'clarification_requested', 'overdue']);
    }

    private function assertActorParticipant(int $projectId, int $organizationId): void
    {
        $active = Project::query()->whereKey($projectId)->where('organization_id', $organizationId)->exists()
            || DB::table('project_organization')->where('project_id', $projectId)
                ->where('organization_id', $organizationId)->where('is_active', true)->exists();
        if (! $active) {
            throw new DomainException(trans_message('change_management.errors.project_scope'));
        }
    }

    private function assertActiveParticipant(int $projectId, int $organizationId): void
    {
        $this->assertActorParticipant($projectId, $organizationId);
    }

    private function assertPermission(int $userId, int $organizationId, string $permission): void
    {
        if (! $this->hasPermission($userId, $organizationId, $permission)) {
            throw new DomainException(trans_message('change_management.errors.rfi_forbidden'));
        }
    }

    private function hasPermission(int $userId, int $organizationId, string $permission): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User
            && $this->authorization->can($user, $permission, ['organization_id' => $organizationId]);
    }

    private function assertDirectNeighbor(int $projectId, int $fromOrganizationId, int $toOrganizationId): void
    {
        if (! $this->participants->isConfigured($projectId)) {
            throw new DomainException(trans_message('change_management.errors.rfi_hierarchy_not_configured'));
        }
        $neighborIds = array_map('intval', [
            ...array_filter([$this->participants->getParent($projectId, $fromOrganizationId)]),
            ...$this->participants->getChildren($projectId, $fromOrganizationId),
        ]);
        if (! in_array($toOrganizationId, $neighborIds, true)) {
            throw new DomainException(trans_message('change_management.errors.rfi_recipient_not_neighbor'));
        }
    }

    private function assertTransitionStatus(string $status, array $allowed): void
    {
        if (! in_array($status, $allowed, true)) {
            throw new ConflictHttpException(trans_message('change_management.errors.rfi_transition_conflict'));
        }
    }

    public function createChange(int $organizationId, int $userId, array $data): ChangeRequest
    {
        $this->assertProjectInOrganization((int) $data['project_id'], $organizationId);
        $monetary = $data['monetary_context'];
        $allocationId = (int) $monetary['contract_project_allocation_id'];
        $allocationExists = ContractProjectAllocation::query()
            ->whereKey($allocationId)
            ->where('project_id', (int) $data['project_id'])
            ->whereHas('contract', static fn ($query) => $query->where('organization_id', $organizationId))
            ->exists();
        if (! $allocationExists) {
            throw new DomainException(trans_message('change_management.errors.project_scope'));
        }

        if (($data['related_rfi_id'] ?? null) !== null) {
            $relatedRfi = $this->findRfi($organizationId, (int) $data['related_rfi_id']);
            if ((int) $relatedRfi->project_id !== (int) $data['project_id']) {
                throw new DomainException(trans_message('change_management.errors.project_scope'));
            }
        }

        return DB::transaction(function () use (
            $organizationId,
            $userId,
            $data,
            $monetary,
            $allocationId,
        ): ChangeRequest {
            $change = ChangeRequest::create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'created_by_user_id' => $userId,
                'related_rfi_id' => $data['related_rfi_id'] ?? null,
                'change_number' => $data['change_number'] ?? $this->nextNumber(ChangeRequest::class, $organizationId, 'CHG', 'change_number'),
                'title' => $data['title'],
                'reason' => $data['reason'],
                'description' => $data['description'],
                'initiator_type' => $data['initiator_type'],
                'status' => 'draft',
                'affected_schedule_task_ids' => $this->integerList($data['affected_schedule_task_ids'] ?? []),
                'affected_estimate_item_ids' => $this->integerList($data['affected_estimate_item_ids'] ?? []),
                'linked_entities' => $data['linked_entities'] ?? [],
                'reporting_currency' => mb_strtoupper((string) $monetary['currency']),
                'reporting_contract_project_allocation_id' => $allocationId,
                'contingency_opening_minor' => ExactDecimal::minor((string) $monetary['contingency_opening_amount']),
                'contingency_allocation_minor' => ExactDecimal::minor((string) $monetary['contingency_allocation_amount']),
                'contingency_release_minor' => ExactDecimal::minor((string) $monetary['contingency_release_amount']),
            ]);
            $this->changeEvents->record($change, 'create', CarbonImmutable::now(), $userId);

            return $change->load(['impact', 'approvals', 'variationOrders', 'relatedRfi']);
        });
    }

    public function findChange(int $organizationId, int $id): ChangeRequest
    {
        $change = ChangeRequest::query()
            ->with(['impact', 'approvals', 'variationOrders', 'relatedRfi'])
            ->forOrganization($organizationId)
            ->find($id);

        if (! $change instanceof ChangeRequest) {
            throw new DomainException(trans_message('change_management.errors.change_not_found'));
        }

        return $change;
    }

    public function submitChange(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['draft'],
            'submitted',
            function (ChangeRequest $lockedChange): void {
                $lockedChange->forceFill([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ])->save();
                $this->changeEvents->record($lockedChange, 'submit', CarbonImmutable::now(), null);
            },
        );
    }

    public function assessImpact(ChangeRequest $change, array $data): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['submitted', 'impact_assessment'],
            'impact_assessment',
            function (ChangeRequest $lockedChange) use ($data): void {
                $impactData = [
                    'organization_id' => $lockedChange->organization_id,
                    'cost_delta' => $data['cost_delta'] ?? 0,
                    'schedule_delta_days' => $data['schedule_delta_days'] ?? 0,
                    'requires_contract_change' => (bool) ($data['requires_contract_change'] ?? false),
                    'requires_estimate_revision' => (bool) ($data['requires_estimate_revision'] ?? false),
                    'requires_procurement_update' => (bool) ($data['requires_procurement_update'] ?? false),
                    'requires_customer_approval' => (bool) ($data['requires_customer_approval'] ?? false),
                    'affected_schedule_task_ids' => $this->integerList($data['affected_schedule_task_ids'] ?? $lockedChange->affected_schedule_task_ids ?? []),
                    'affected_estimate_item_ids' => $this->integerList($data['affected_estimate_item_ids'] ?? $lockedChange->affected_estimate_item_ids ?? []),
                    'affected_contract_ids' => $this->integerList($data['affected_contract_ids'] ?? []),
                    'summary' => $data['summary'] ?? null,
                ];

                $lockedChange->impact()->updateOrCreate([], $impactData);
                $lockedChange->forceFill([
                    'status' => 'impact_assessment',
                    'affected_schedule_task_ids' => $impactData['affected_schedule_task_ids'],
                    'affected_estimate_item_ids' => $impactData['affected_estimate_item_ids'],
                ])->save();
                $this->changeEvents->record($lockedChange, 'impact_assessment', CarbonImmutable::now(), null);
            },
            fn (ChangeRequest $lockedChange): bool => $this->impactMatches($lockedChange, $data),
        );
    }

    public function startInternalReview(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['impact_assessment'],
            'internal_review',
            function (ChangeRequest $lockedChange): void {
                $this->assertImpactExists($lockedChange);
                $lockedChange->forceFill(['status' => 'internal_review'])->save();
                $this->changeEvents->record($lockedChange, 'review', CarbonImmutable::now(), null);
            },
        );
    }

    public function startCustomerReview(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['internal_review'],
            'customer_review',
            function (ChangeRequest $lockedChange): void {
                $impact = $this->assertImpactExists($lockedChange);
                if (! $impact->requires_customer_approval) {
                    throw new DomainException(trans_message('change_management.errors.customer_approval_not_required'));
                }
                $lockedChange->forceFill(['status' => 'customer_review'])->save();
                $this->changeEvents->record($lockedChange, 'review', CarbonImmutable::now(), null);
            },
        );
    }

    public function approveChange(
        ChangeRequest $change,
        int $userId,
        string $approvedCostAmount,
        ?string $comment = null,
    ): ChangeRequest {
        return $this->approve($change, $userId, 'internal', $approvedCostAmount, $comment);
    }

    public function customerApprove(
        ChangeRequest $change,
        int $userId,
        string $approvedCostAmount,
        ?string $comment = null,
    ): ChangeRequest {
        return $this->approve($change, $userId, 'customer', $approvedCostAmount, $comment);
    }

    public function createVariationOrder(ChangeRequest $change, array $data): VariationOrder
    {
        return DB::transaction(function () use ($change, $data): VariationOrder {
            $lockedChange = ChangeRequest::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedChange->status, ['approved', 'implemented', 'closed'], true)) {
                throw new DomainException(trans_message('change_management.errors.variation_requires_approved_change'));
            }

            $variationNumber = trim((string) $data['variation_number']);
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    "variation:{$lockedChange->organization_id}:{$variationNumber}",
                ]);
            }

            $amount = (string) ($data['amount'] ?? $lockedChange->impact?->cost_delta ?? 0);
            $amountMinor = ExactDecimal::minor($amount);
            if ($amountMinor < 0) {
                throw new DomainException(trans_message('change_management.errors.variation_amount_invalid'));
            }

            $existing = VariationOrder::query()
                ->where('organization_id', $lockedChange->organization_id)
                ->where('variation_number', $variationNumber)
                ->first();
            if ($existing instanceof VariationOrder) {
                if ((int) $existing->change_request_id !== (int) $lockedChange->id
                    || ExactDecimal::minor((string) $existing->amount) !== $amountMinor
                    || (int) $existing->schedule_delta_days !== (int) ($data['schedule_delta_days'] ?? $lockedChange->impact?->schedule_delta_days ?? 0)
                    || (string) ($existing->description ?? '') !== (string) ($data['description'] ?? '')) {
                    throw new DomainException(trans_message('change_management.errors.variation_idempotency_conflict'));
                }

                return $existing;
            }

            $approval = ChangeApproval::query()
                ->where('change_request_id', $lockedChange->id)
                ->where('status', 'approved')
                ->whereNotNull('approved_cost_minor')
                ->latest('decided_at')
                ->latest('id')
                ->first();
            if (! $approval instanceof ChangeApproval) {
                throw new DomainException(trans_message('change_management.errors.monetary_context_missing'));
            }

            $allocatedMinor = VariationOrder::query()
                ->where('change_request_id', $lockedChange->id)
                ->lockForUpdate()
                ->get(['amount'])
                ->sum(static fn (VariationOrder $order): int => ExactDecimal::minor((string) $order->amount));
            if ($allocatedMinor + $amountMinor > (int) $approval->approved_cost_minor) {
                throw new DomainException(trans_message('change_management.errors.variation_exceeds_approved_amount'));
            }

            return VariationOrder::query()->create([
                'organization_id' => $lockedChange->organization_id,
                'change_request_id' => $lockedChange->id,
                'variation_number' => $variationNumber,
                'amount' => $amount,
                'schedule_delta_days' => $data['schedule_delta_days'] ?? $lockedChange->impact?->schedule_delta_days ?? 0,
                'description' => $data['description'] ?? null,
            ]);
        }, 3);
    }

    public function implementChange(ChangeRequest $change, ?string $comment = null): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['approved'],
            'implemented',
            function (ChangeRequest $lockedChange) use ($comment): void {
                $lockedChange->forceFill([
                    'status' => 'implemented',
                    'implementation_comment' => $comment,
                    'implemented_at' => now(),
                ])->save();
                $this->changeEvents->record($lockedChange, 'implement', CarbonImmutable::now(), null);
            },
            static fn (ChangeRequest $lockedChange): bool => $lockedChange->implementation_comment === $comment,
        );
    }

    public function closeChange(ChangeRequest $change): ChangeRequest
    {
        return $this->lockedTransition(
            $change,
            ['implemented'],
            'closed',
            function (ChangeRequest $lockedChange): void {
                $lockedChange->forceFill([
                    'status' => 'closed',
                    'closed_at' => now(),
                ])->save();
                $this->changeEvents->record($lockedChange, 'close', CarbonImmutable::now(), null);
            },
            invalidStatusMessage: 'change_management.errors.implementation_required',
        );
    }

    public function createClaim(int $organizationId, int $userId, array $data): ChangeClaim
    {
        $this->assertProjectInOrganization((int) $data['project_id'], $organizationId);

        if (($data['change_request_id'] ?? null) !== null) {
            $change = $this->findChange($organizationId, (int) $data['change_request_id']);

            if ((int) $change->project_id !== (int) $data['project_id']) {
                throw new DomainException(trans_message('change_management.errors.project_scope'));
            }
        }

        return DB::transaction(function () use ($organizationId, $userId, $data): ChangeClaim {
            $claim = ChangeClaim::create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'change_request_id' => $data['change_request_id'] ?? null,
                'created_by_user_id' => $userId,
                'claim_number' => $data['claim_number'] ?? $this->nextNumber(ChangeClaim::class, $organizationId, 'CL', 'claim_number'),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'] ?? 0,
                'status' => 'submitted',
                'evidence' => $data['evidence'] ?? [],
            ]);
            if ($claim->change_request_id !== null) {
                $change = $this->findChange($organizationId, (int) $claim->change_request_id);
                $this->changeEvents->linkClaim($change, $claim);
            }

            return $claim->load(['changeRequest']);
        });
    }

    private function approve(
        ChangeRequest $change,
        int $userId,
        string $approvalType,
        string $approvedCostAmount,
        ?string $comment,
    ): ChangeRequest {
        $approvedCostMinor = ExactDecimal::minor($approvedCostAmount);

        return DB::transaction(function () use (
            $change,
            $userId,
            $approvalType,
            $approvedCostMinor,
            $comment,
        ): ChangeRequest {
            $lockedChange = ChangeRequest::query()
                ->where('organization_id', $change->organization_id)
                ->whereKey($change->id)
                ->lockForUpdate()
                ->firstOrFail();
            $expectedStatus = $approvalType === 'customer' ? 'customer_review' : 'internal_review';
            if ($lockedChange->status === 'approved') {
                $existing = $lockedChange->approvals()
                    ->where('approval_type', $approvalType)
                    ->where('status', 'approved')
                    ->latest('id')
                    ->first();
                if ($existing instanceof ChangeApproval
                    && (int) $existing->approved_by_user_id === $userId
                    && (int) $existing->approved_cost_minor === $approvedCostMinor
                    && (string) $existing->currency === (string) $lockedChange->reporting_currency
                    && $existing->comment === $comment) {
                    return $this->reloadChange($lockedChange);
                }
            }
            $this->assertStatus((string) $lockedChange->status, [$expectedStatus]);
            $impact = $this->assertImpactExists($lockedChange);
            if ($approvalType === 'customer' && ! $impact->requires_customer_approval) {
                throw new DomainException(trans_message('change_management.errors.customer_approval_not_required'));
            }
            if ($approvalType === 'internal' && $impact->requires_customer_approval) {
                throw new DomainException(trans_message('change_management.errors.customer_approval_required'));
            }
            $change = $lockedChange;
            if ($change->reporting_currency === null) {
                throw new DomainException(trans_message('change_management.errors.monetary_context_missing'));
            }
            ChangeApproval::create([
                'organization_id' => $change->organization_id,
                'change_request_id' => $change->id,
                'approved_by_user_id' => $userId,
                'approval_type' => $approvalType,
                'status' => 'approved',
                'comment' => $comment,
                'approved_cost_minor' => $approvedCostMinor,
                'currency' => (string) $change->reporting_currency,
                'decided_at' => now(),
            ]);

            $change->forceFill([
                'status' => 'approved',
                'approved_at' => now(),
            ])->save();
            $this->changeEvents->record($change, 'approve', CarbonImmutable::now(), $userId);

            return $this->reloadChange($change);
        });
    }

    private function assertProjectInOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->whereKey($projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            throw new DomainException(trans_message('change_management.errors.project_scope'));
        }
    }

    private function assertStatus(string $currentStatus, array $allowedStatuses): void
    {
        if (! in_array($currentStatus, $allowedStatuses, true)) {
            throw new DomainException(trans_message('change_management.errors.invalid_status'));
        }
    }

    private function lockedTransition(
        ChangeRequest $change,
        array $allowedStatuses,
        string $targetStatus,
        callable $transition,
        ?callable $isConverged = null,
        ?string $invalidStatusMessage = null,
    ): ChangeRequest {
        return DB::transaction(function () use (
            $change,
            $allowedStatuses,
            $targetStatus,
            $transition,
            $isConverged,
            $invalidStatusMessage,
        ): ChangeRequest {
            $lockedChange = ChangeRequest::query()
                ->where('organization_id', $change->organization_id)
                ->whereKey($change->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedChange->status === $targetStatus
                && ($isConverged === null || $isConverged($lockedChange))) {
                return $this->reloadChange($lockedChange);
            }
            if ($invalidStatusMessage !== null
                && ! in_array((string) $lockedChange->status, $allowedStatuses, true)) {
                throw new DomainException(trans_message($invalidStatusMessage));
            }
            $this->assertStatus((string) $lockedChange->status, $allowedStatuses);
            $transition($lockedChange);

            return $this->reloadChange($lockedChange);
        });
    }

    private function impactMatches(ChangeRequest $change, array $data): bool
    {
        $impact = $this->assertImpactExists($change);

        return ExactDecimal::minor((string) $impact->cost_delta)
                === ExactDecimal::minor((string) ($data['cost_delta'] ?? 0))
            && (int) $impact->schedule_delta_days === (int) ($data['schedule_delta_days'] ?? 0)
            && (bool) $impact->requires_contract_change === (bool) ($data['requires_contract_change'] ?? false)
            && (bool) $impact->requires_estimate_revision === (bool) ($data['requires_estimate_revision'] ?? false)
            && (bool) $impact->requires_procurement_update === (bool) ($data['requires_procurement_update'] ?? false)
            && (bool) $impact->requires_customer_approval === (bool) ($data['requires_customer_approval'] ?? false)
            && $this->integerList((array) $impact->affected_schedule_task_ids)
                === $this->integerList($data['affected_schedule_task_ids'] ?? $change->affected_schedule_task_ids ?? [])
            && $this->integerList((array) $impact->affected_estimate_item_ids)
                === $this->integerList($data['affected_estimate_item_ids'] ?? $change->affected_estimate_item_ids ?? [])
            && $this->integerList((array) $impact->affected_contract_ids)
                === $this->integerList($data['affected_contract_ids'] ?? [])
            && $impact->summary === ($data['summary'] ?? null);
    }

    private function assertImpactExists(ChangeRequest $change): object
    {
        $change->loadMissing('impact');

        if ($change->impact === null) {
            throw new DomainException(trans_message('change_management.errors.impact_required'));
        }

        return $change->impact;
    }

    private function reloadChange(ChangeRequest $change): ChangeRequest
    {
        return $change->refresh()->load(['impact', 'approvals', 'variationOrders', 'relatedRfi']);
    }

    private function nextNumber(string $modelClass, int $organizationId, string $prefix, string $column): string
    {
        $count = $modelClass::query()
            ->where('organization_id', $organizationId)
            ->count() + 1;

        return sprintf('%s-%05d', $prefix, $count);
    }

    private function integerList(array $values): array
    {
        return array_values(array_map('intval', Arr::where($values, static fn ($value): bool => $value !== null && $value !== '')));
    }
}
