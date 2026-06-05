<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Services;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\Organization;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class QualityDefectService
{
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
    ) {
    }

    public function paginate(int $organizationId, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = QualityDefect::forOrganization($organizationId)
            ->with(self::RESOURCE_RELATIONS);

        if (!empty($filters['status'])) {
            $query->withStatus((string) $filters['status']);
        }

        if (!empty($filters['project_id'])) {
            $query->where('project_id', (int) $filters['project_id']);
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }

        if (!empty($filters['severity'])) {
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

    public function find(int $id, int $organizationId): ?QualityDefect
    {
        return QualityDefect::forOrganization($organizationId)
            ->with(self::RESOURCE_RELATIONS)
            ->find($id);
    }

    public function create(int $organizationId, int $userId, array $data): QualityDefect
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalUserBelongsToOrganization($data['assigned_to'] ?? null, $organizationId);
        $this->assertOptionalContractorBelongsToOrganization($data['contractor_id'] ?? null, $organizationId);

        return DB::transaction(function () use ($organizationId, $userId, $data): QualityDefect {
            $status = empty($data['assigned_to'])
                ? QualityDefectStatusEnum::OPEN
                : QualityDefectStatusEnum::ASSIGNED;

            $defect = QualityDefect::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
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

            $this->storePhotos($defect, $data['photos'] ?? [], $organizationId, $userId);
            $this->recordStatus($defect, null, $status, $userId, trans_message('quality_control.history.created'));

            return $defect->fresh(self::RESOURCE_RELATIONS);
        });
    }

    public function assign(QualityDefect $defect, int $assigneeId, int $userId, ?string $comment = null): QualityDefect
    {
        if (!$defect->canBeAssigned()) {
            throw new DomainException(trans_message('quality_control.errors.assign_invalid_status'));
        }

        $this->assertOptionalUserBelongsToOrganization($assigneeId, (int) $defect->organization_id);

        return $this->transition($defect, QualityDefectStatusEnum::ASSIGNED, $userId, [
            'assigned_to' => $assigneeId,
        ], $comment);
    }

    public function start(QualityDefect $defect, int $userId, ?string $comment = null): QualityDefect
    {
        if (!$defect->canBeStarted()) {
            throw new DomainException(trans_message('quality_control.errors.start_invalid_status'));
        }

        return $this->transition($defect, QualityDefectStatusEnum::IN_PROGRESS, $userId, [], $comment);
    }

    public function resolve(QualityDefect $defect, int $userId, array $data): QualityDefect
    {
        if (!$defect->canBeResolved()) {
            throw new DomainException(trans_message('quality_control.errors.resolve_invalid_status'));
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        $photos = $data['photos'] ?? [];

        if ($defect->inspection_required && $comment === '' && $photos === []) {
            throw new DomainException(trans_message('quality_control.errors.result_evidence_required'));
        }

        return DB::transaction(function () use ($defect, $userId, $comment, $photos): QualityDefect {
            $this->storePhotos($defect, $photos, (int) $defect->organization_id, $userId);

            return $this->transition(
                $defect,
                QualityDefectStatusEnum::READY_FOR_REVIEW,
                $userId,
                ['resolved_at' => now()],
                $comment !== '' ? $comment : null
            );
        });
    }

    public function verify(QualityDefect $defect, int $userId, bool $accepted, ?string $comment = null): QualityDefect
    {
        if (!$defect->canBeVerified()) {
            throw new DomainException(trans_message('quality_control.errors.verify_invalid_status'));
        }

        return $this->transition(
            $defect,
            $accepted ? QualityDefectStatusEnum::RESOLVED : QualityDefectStatusEnum::REJECTED,
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

        return $this->transition($defect, QualityDefectStatusEnum::REJECTED, $userId, [], $comment);
    }

    public function cancel(QualityDefect $defect, int $userId, string $comment): QualityDefect
    {
        if (!in_array($defect->status, [
            QualityDefectStatusEnum::DRAFT,
            QualityDefectStatusEnum::OPEN,
            QualityDefectStatusEnum::ASSIGNED,
            QualityDefectStatusEnum::IN_PROGRESS,
            QualityDefectStatusEnum::REJECTED,
        ], true)) {
            throw new DomainException(trans_message('quality_control.errors.cancel_invalid_status'));
        }

        return $this->transition($defect, QualityDefectStatusEnum::CANCELLED, $userId, [], $comment);
    }

    private function transition(
        QualityDefect $defect,
        QualityDefectStatusEnum $toStatus,
        int $userId,
        array $extra = [],
        ?string $comment = null,
    ): QualityDefect {
        return DB::transaction(function () use ($defect, $toStatus, $userId, $extra, $comment): QualityDefect {
            $fromStatus = $defect->status;
            $defect->update(array_merge($extra, [
                'status' => $toStatus,
            ]));

            $this->recordStatus($defect, $fromStatus, $toStatus, $userId, $comment);

            return $defect->fresh(self::RESOURCE_RELATIONS);
        });
    }

    private function storePhotos(QualityDefect $defect, array $photos, int $organizationId, int $userId): void
    {
        $organization = Organization::query()->find($organizationId);

        foreach ($photos as $photo) {
            $url = $photo['url'] ?? null;

            if (($photo['file'] ?? null) instanceof UploadedFile) {
                $url = $this->fileService->upload(
                    $photo['file'],
                    "quality-control/defects/{$defect->id}",
                    null,
                    'private',
                    $organization
                );

                if ($url === false) {
                    throw new DomainException(trans_message('quality_control.errors.photo_upload_failed'));
                }
            }

            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            $type = $photo['type'] ?? null;

            if (!is_string($type) || trim($type) === '') {
                throw new DomainException(trans_message('quality_control.validation.photo_type_required'));
            }

            $defect->photos()->create([
                'organization_id' => $organizationId,
                'uploaded_by' => $userId,
                'type' => $type,
                'url' => $url,
                'caption' => $photo['caption'] ?? null,
                'metadata' => $photo['metadata'] ?? null,
            ]);
        }
    }

    private function recordStatus(
        QualityDefect $defect,
        ?QualityDefectStatusEnum $fromStatus,
        QualityDefectStatusEnum $toStatus,
        int $userId,
        ?string $comment = null,
    ): void {
        $defect->statusHistory()->create([
            'organization_id' => $defect->organization_id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => $comment,
            'changed_by' => $userId,
            'changed_at' => now(),
        ]);
    }

    private function assertProjectBelongsToOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
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

        if (!$exists) {
            throw new DomainException(trans_message('quality_control.errors.assignee_not_found'));
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

        if (!$exists) {
            throw new DomainException(trans_message('quality_control.errors.contractor_not_found'));
        }
    }
}
