<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Services;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectStatusHistory;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowOwnerEventSink;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class QualityDefectService
{
    private const PHOTO_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const RESOURCE_RELATIONS = [
        'organization',
        'project',
        'contractor',
        'createdBy',
        'assignedUser',
        'photos.uploadedBy',
        'statusHistory.changedBy',
    ];

    public function __construct(
        private readonly QualityDefectNumberGenerator $numberGenerator,
        private readonly FileService $fileService,
        private readonly QualityDefectFlowOwnerEventSink $flowRecorder,
    ) {}

    public function paginate(int $organizationId, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = QualityDefect::forOrganization($organizationId)
            ->with(self::RESOURCE_RELATIONS);

        if (array_key_exists('project_ids', $filters)) {
            $query->whereIn('project_id', $filters['project_ids']);
        }

        if (! empty($filters['status'])) {
            $query->withStatus((string) $filters['status']);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        if (! empty($filters['severity'])) {
            $query->where('severity', (string) $filters['severity']);
        }

        if (array_key_exists('overdue', $filters) && filter_var($filters['overdue'], FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNotIn('status', [
                QualityDefectStatusEnum::RESOLVED->value,
                QualityDefectStatusEnum::CANCELLED->value,
            ])->whereDate('due_date', '<', now()->toDateString());
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['created_at', 'due_date', 'severity', 'status'], true)
            ? (string) $filters['sort_by']
            : 'created_at';
        $sortDir = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    public function find(int $id, int $organizationId, ?array $projectIds = null): ?QualityDefect
    {
        return QualityDefect::forOrganization($organizationId)
            ->with(self::RESOURCE_RELATIONS)
            ->when($projectIds !== null, fn ($query) => $query->whereIn('project_id', $projectIds))
            ->find($id);
    }

    public function create(int $organizationId, int $userId, array $data): QualityDefect
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalUserBelongsToOrganization($data['assigned_to'] ?? null, $organizationId);
        if (($data['kind'] ?? 'construction') === 'project') {
            $this->assertProjectAssignee($data['assigned_to'] ?? null, $organizationId, (int) $data['project_id']);
        }
        $this->assertOptionalContractorBelongsToOrganization($data['contractor_id'] ?? null, $organizationId);

        $storedPhotoKeys = [];
        $transaction = function () use ($organizationId, $userId, $data, &$storedPhotoKeys): QualityDefect {
            $status = empty($data['assigned_to'])
                ? QualityDefectStatusEnum::OPEN
                : QualityDefectStatusEnum::ASSIGNED;

            $defect = QualityDefect::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'kind' => $data['kind'] ?? 'construction',
                'contractor_id' => $data['contractor_id'] ?? null,
                'created_by' => $userId,
                'assigned_to' => $data['assigned_to'] ?? null,
                'defect_number' => $this->numberGenerator->generate($organizationId),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'severity' => $data['severity'],
                'status' => $status,
                'location_name' => $data['location_name'] ?? null,
                'schedule_task_id' => $data['schedule_task_id'] ?? null,
                'construction_journal_entry_id' => $data['construction_journal_entry_id'] ?? null,
                'completed_work_id' => $data['completed_work_id'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'inspection_required' => (bool) $data['inspection_required'],
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->storePhotos($defect, $data['photos'] ?? [], $organizationId, $userId, $storedPhotoKeys);
            $history = $this->recordStatus(
                $defect,
                null,
                $status,
                $userId,
                trans_message('quality_control.history.created'),
            );
            if ($defect->kind === 'construction') {
                $this->flowRecorder->record($defect, $history, QualityDefectFlowEventKind::CREATED);
            }

            return $defect->fresh(self::RESOURCE_RELATIONS);
        };

        try {
            return DB::transaction($transaction);
        } catch (\Throwable $exception) {
            $this->deleteStoredPhotoObjects($storedPhotoKeys, $organizationId);
            throw $exception;
        }
    }

    public function assign(QualityDefect $defect, int $assigneeId, int $userId, ?string $comment = null): QualityDefect
    {
        if (! $defect->canBeAssigned()) {
            throw new DomainException(trans_message('quality_control.errors.assign_invalid_status'));
        }

        $this->assertOptionalUserBelongsToOrganization($assigneeId, (int) $defect->organization_id);
        if ($defect->kind === 'project') {
            $this->assertProjectAssignee($assigneeId, (int) $defect->organization_id, (int) $defect->project_id);
        }

        return $this->transition(
            $defect,
            QualityDefectStatusEnum::ASSIGNED,
            QualityDefectFlowEventKind::ASSIGNED,
            $userId,
            ['assigned_to' => $assigneeId],
            $comment,
        );
    }

    public function assignToProjectParticipant(
        QualityDefect $defect,
        int $assigneeId,
        int $userId,
        ?string $comment = null,
    ): QualityDefect {
        $this->assertProjectAssignee($assigneeId, (int) $defect->organization_id, (int) $defect->project_id);

        return $this->assign($defect, $assigneeId, $userId, $comment);
    }

    /** @return list<array{id: int, name: string, email: string|null}> */
    public function eligibleAssignees(QualityDefect $defect): array
    {
        return User::query()
            ->whereHas('organizations', static function ($query) use ($defect): void {
                $query->where('organizations.id', $defect->organization_id)
                    ->where('organization_user.is_active', true);
            })
            ->whereExists(static function ($query) use ($defect): void {
                $query->selectRaw('1')->from('project_user')
                    ->whereColumn('project_user.user_id', 'users.id')
                    ->where('project_user.project_id', $defect->project_id)
                    ->where('project_user.is_active', true);
            })
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->values()
            ->all();
    }

    public function start(QualityDefect $defect, int $userId, ?string $comment = null): QualityDefect
    {
        if (! $defect->canBeStarted()) {
            throw new DomainException(trans_message('quality_control.errors.start_invalid_status'));
        }

        return $this->transition(
            $defect,
            QualityDefectStatusEnum::IN_PROGRESS,
            QualityDefectFlowEventKind::STARTED,
            $userId,
            [],
            $comment,
        );
    }

    public function resolve(QualityDefect $defect, int $userId, array $data): QualityDefect
    {
        if (! $defect->canBeResolved()) {
            throw new DomainException(trans_message('quality_control.errors.resolve_invalid_status'));
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        $photos = $data['photos'] ?? [];

        if ($defect->inspection_required && $comment === '' && $photos === []) {
            throw new DomainException(trans_message('quality_control.errors.result_evidence_required'));
        }

        $storedPhotoKeys = [];
        $transaction = function () use ($defect, $userId, $comment, $photos, &$storedPhotoKeys): QualityDefect {
            $defect = $this->lockCurrentProjectIssue($defect);
            $this->storePhotos($defect, $photos, (int) $defect->organization_id, $userId, $storedPhotoKeys);

            return $this->transition(
                $defect,
                QualityDefectStatusEnum::READY_FOR_REVIEW,
                QualityDefectFlowEventKind::SUBMITTED_FOR_REVIEW,
                $userId,
                ['resolved_at' => now()],
                $comment !== '' ? $comment : null
            );
        };

        try {
            return DB::transaction($transaction);
        } catch (\Throwable $exception) {
            $this->deleteStoredPhotoObjects($storedPhotoKeys, (int) $defect->organization_id);
            throw $exception;
        }
    }

    public function verify(QualityDefect $defect, int $userId, bool $accepted, ?string $comment = null): QualityDefect
    {
        if (! $defect->canBeVerified()) {
            throw new DomainException(trans_message('quality_control.errors.verify_invalid_status'));
        }

        return $this->transition(
            $defect,
            $accepted ? QualityDefectStatusEnum::RESOLVED : QualityDefectStatusEnum::REJECTED,
            $accepted
                ? QualityDefectFlowEventKind::VERIFIED_RESOLVED
                : QualityDefectFlowEventKind::RETURNED_FOR_REWORK,
            $userId,
            ['verified_at' => now()],
            $comment
        );
    }

    public function reject(QualityDefect $defect, int $userId, string $comment): QualityDefect
    {
        if (in_array($defect->status, [
            QualityDefectStatusEnum::RESOLVED,
            QualityDefectStatusEnum::CANCELLED,
        ], true)) {
            throw new DomainException(trans_message('quality_control.errors.reject_invalid_status'));
        }

        return $this->transition(
            $defect,
            QualityDefectStatusEnum::REJECTED,
            QualityDefectFlowEventKind::REJECTED,
            $userId,
            [],
            $comment,
        );
    }

    public function cancel(QualityDefect $defect, int $userId, string $comment): QualityDefect
    {
        if (! in_array($defect->status, [
            QualityDefectStatusEnum::DRAFT,
            QualityDefectStatusEnum::OPEN,
            QualityDefectStatusEnum::ASSIGNED,
            QualityDefectStatusEnum::IN_PROGRESS,
            QualityDefectStatusEnum::REJECTED,
        ], true)) {
            throw new DomainException(trans_message('quality_control.errors.cancel_invalid_status'));
        }

        return $this->transition(
            $defect,
            QualityDefectStatusEnum::CANCELLED,
            QualityDefectFlowEventKind::CANCELLED,
            $userId,
            [],
            $comment,
            QualityDefectFlowTerminalReason::CANCELLED_BY_USER,
        );
    }

    private function transition(
        QualityDefect $defect,
        QualityDefectStatusEnum $toStatus,
        QualityDefectFlowEventKind $eventKind,
        int $userId,
        array $extra = [],
        ?string $comment = null,
        ?QualityDefectFlowTerminalReason $terminalReason = null,
    ): QualityDefect {
        return DB::transaction(function () use (
            $defect,
            $toStatus,
            $eventKind,
            $userId,
            $extra,
            $comment,
            $terminalReason,
        ): QualityDefect {
            $defect = $this->lockCurrentProjectIssue($defect);
            if ($defect->kind === 'project') {
                $extra['row_version'] = (int) $defect->getAttribute('row_version') + 1;
            }
            $fromStatus = $defect->status;
            $defect->update(array_merge($extra, [
                'status' => $toStatus,
            ]));

            $history = $this->recordStatus($defect, $fromStatus, $toStatus, $userId, $comment);
            if ($defect->kind === 'construction') {
                $this->flowRecorder->record($defect, $history, $eventKind, $terminalReason);
            }

            return $defect->fresh(self::RESOURCE_RELATIONS);
        });
    }

    public function assertExpectedRevision(QualityDefect $defect, int $expectedRevision): void
    {
        if ($defect->kind === 'project' && (int) $defect->getAttribute('row_version') !== $expectedRevision) {
            throw new DomainException(trans_message('design_issues.errors.stale_revision'));
        }
    }

    private function lockCurrentProjectIssue(QualityDefect $defect): QualityDefect
    {
        if ($defect->kind !== 'project') {
            return $defect;
        }

        $locked = QualityDefect::query()->forOrganization((int) $defect->organization_id)
            ->where('project_id', $defect->project_id)->projectIssues()->whereKey($defect->id)
            ->lockForUpdate()->firstOrFail();

        if ((int) $locked->getAttribute('row_version') !== (int) $defect->getAttribute('row_version')
            || $locked->status !== $defect->status) {
            throw new DomainException(trans_message('design_issues.errors.stale_revision'));
        }

        return $locked;
    }

    private function storePhotos(
        QualityDefect $defect,
        array $photos,
        int $organizationId,
        int $userId,
        array &$storedKeys,
    ): void {
        foreach ($photos as $photo) {
            $url = $photo['url'] ?? null;
            $type = $photo['type'] ?? null;
            $storedFile = null;
            $file = $photo['file'] ?? null;

            if ($file instanceof UploadedFile) {
                if (! is_string($type) || trim($type) === '') {
                    throw new DomainException(trans_message('quality_control.validation.photo_type_required'));
                }
                if (! $file->isValid()) {
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                }

                try {
                    $mime = $file->getMimeType();
                } catch (\Throwable) {
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                }

                $extension = is_string($mime) ? (self::PHOTO_MIME_EXTENSIONS[$mime] ?? null) : null;
                if ($extension === null) {
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                }

                try {
                    $sha256 = hash_file('sha256', $file->getPathname());
                    $contents = fopen($file->getPathname(), 'rb');
                } catch (\Throwable) {
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                }

                if (! is_string($sha256) || ! is_resource($contents)) {
                    if (is_resource($contents)) {
                        fclose($contents);
                    }
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                }

                $key = "org-{$organizationId}/quality-control/defects/{$defect->id}/".Str::uuid().".{$extension}";
                try {
                    $storedFile = $this->fileService->putPrivate($key, $contents, $mime, $sha256);
                } catch (\Throwable $exception) {
                    Log::warning('quality_defect_photo_upload_failed', [
                        'organization_id' => $organizationId,
                        'quality_defect_id' => $defect->id,
                        'storage_key' => $key,
                        'exception' => $exception::class,
                    ]);
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                } finally {
                    fclose($contents);
                }

                $storedKeys[] = $key;
                if ($storedFile->key !== $key) {
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                }

                $url = $storedFile->key;
            }

            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            if (! is_string($type) || trim($type) === '') {
                throw new DomainException(trans_message('quality_control.validation.photo_type_required'));
            }

            $attributes = [
                'organization_id' => $organizationId,
                'uploaded_by' => $userId,
                'type' => $type,
                'url' => $url,
                'caption' => $photo['caption'] ?? null,
                'metadata' => $photo['metadata'] ?? null,
            ];

            if ($storedFile instanceof CurrentStoredFile) {
                $attributes += [
                    'storage_etag' => $storedFile->etag,
                    'storage_sha256' => $storedFile->sha256,
                    'size_bytes' => $storedFile->sizeBytes,
                    'mime_type' => $storedFile->mime,
                    'storage_identity_verified' => true,
                ];
            }

            $defect->photos()->create($attributes);
        }
    }

    private function deleteStoredPhotoObjects(array $keys, int $organizationId): void
    {
        foreach (array_unique($keys) as $key) {
            try {
                $this->fileService->deleteCurrent($key);
            } catch (\Throwable $exception) {
                Log::warning('quality_defect_photo_storage_cleanup_failed', [
                    'organization_id' => $organizationId,
                    'storage_key' => $key,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function recordStatus(
        QualityDefect $defect,
        ?QualityDefectStatusEnum $fromStatus,
        QualityDefectStatusEnum $toStatus,
        int $userId,
        ?string $comment = null,
    ): QualityDefectStatusHistory {
        /** @var QualityDefectStatusHistory $history */
        $history = $defect->statusHistory()->create([
            'organization_id' => $defect->organization_id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'changed_by' => $userId,
            'changed_at' => now(),
            'reporting_dimensions' => [
                'contractor_id' => $defect->contractor_id === null ? null : (int) $defect->contractor_id,
                'due_date' => $defect->due_date?->toDateString(),
                'project_id' => (int) $defect->project_id,
                'schedule_task_id' => $defect->schedule_task_id === null ? null : (int) $defect->schedule_task_id,
                'severity' => $defect->severity->value,
            ],
            'reporting_evidence_refs' => [],
        ]);

        if ($defect->kind === 'project') {
            app(QualityProjectIssueNotificationService::class)->statusChanged($defect, $history);
        }

        return $history;
    }

    private function assertProjectBelongsToOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->accessibleByOrganization($organizationId)
            ->whereKey($projectId)
            ->exists();

        if (! $exists) {
            throw new DomainException(trans_message('quality_control.errors.project_not_found'));
        }
    }

    private function assertOptionalUserBelongsToOrganization(mixed $userId, int $organizationId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $exists = User::query()
            ->where('id', (int) $userId)
            ->where(function ($query) use ($organizationId): void {
                $query->where('current_organization_id', $organizationId)
                    ->orWhereHas('organizations', static function ($relation) use ($organizationId): void {
                        $relation->where('organizations.id', $organizationId)
                            ->where('organization_user.is_active', true);
                    });
            })
            ->exists();

        if (! $exists) {
            throw new DomainException(trans_message('quality_control.errors.assignee_not_found'));
        }
    }

    private function assertProjectAssignee(mixed $userId, int $organizationId, int $projectId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $exists = User::query()->whereKey((int) $userId)
            ->whereHas('organizations', static function ($query) use ($organizationId): void {
                $query->where('organizations.id', $organizationId)->where('organization_user.is_active', true);
            })
            ->whereExists(static function ($query) use ($projectId): void {
                $query->selectRaw('1')->from('project_user')
                    ->whereColumn('project_user.user_id', 'users.id')
                    ->where('project_user.project_id', $projectId)
                    ->where('project_user.is_active', true);
            })->exists();

        if (! $exists) {
            throw new DomainException(trans_message('design_issues.errors.assignee_not_in_project'));
        }
    }

    private function assertOptionalContractorBelongsToOrganization(mixed $contractorId, int $organizationId): void
    {
        if ($contractorId === null || $contractorId === '') {
            return;
        }

        $exists = Contractor::query()
            ->where('id', (int) $contractorId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            throw new DomainException(trans_message('quality_control.errors.contractor_not_found'));
        }
    }
}
