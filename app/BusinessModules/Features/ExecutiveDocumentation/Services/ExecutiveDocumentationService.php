<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveRemarkStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRemark;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class ExecutiveDocumentationService
{
    private const SET_RELATIONS = [
        'project',
        'documents.versions',
        'documents.remarks',
        'transmittal',
    ];

    private const DOCUMENT_RELATIONS = [
        'documentSet',
        'project',
        'versions',
        'remarks',
    ];

    public function __construct(
        private readonly ExecutiveDocumentNumberGenerator $numberGenerator,
        private readonly FileService $fileService,
    ) {
    }

    public function listSets(int $organizationId, array $filters = [], bool $customerOnly = false): Collection
    {
        return ExecutiveDocumentSet::forOrganization($organizationId)
            ->with(self::SET_RELATIONS)
            ->when(!empty($filters['project_id']), static fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when($customerOnly, static fn ($query) => $query->where('status', ExecutiveDocumentStatusEnum::TRANSMITTED->value))
            ->orderByDesc('id')
            ->get();
    }

    public function findSet(int $id, int $organizationId, bool $customerOnly = false): ?ExecutiveDocumentSet
    {
        return ExecutiveDocumentSet::forOrganization($organizationId)
            ->with(self::SET_RELATIONS)
            ->when($customerOnly, static fn ($query) => $query->where('status', ExecutiveDocumentStatusEnum::TRANSMITTED->value))
            ->find($id);
    }

    public function createSet(int $organizationId, int $userId, array $data): ExecutiveDocumentSet
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);

        $set = ExecutiveDocumentSet::query()->create([
            'organization_id' => $organizationId,
            'project_id' => (int) $data['project_id'],
            'created_by' => $userId,
            'set_number' => $this->numberGenerator->generateSetNumber($organizationId),
            'title' => $data['title'],
            'status' => ExecutiveDocumentStatusEnum::DRAFT,
            'stage_name' => $data['stage_name'] ?? null,
            'zone_name' => $data['zone_name'] ?? null,
            'planned_transmittal_date' => $data['planned_transmittal_date'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        return $set->fresh(self::SET_RELATIONS);
    }

    public function addDocument(ExecutiveDocumentSet $set, int $userId, array $data): ExecutiveDocument
    {
        return DB::transaction(function () use ($set, $userId, $data): ExecutiveDocument {
            $document = ExecutiveDocument::query()->create([
                'organization_id' => $set->organization_id,
                'project_id' => $set->project_id,
                'document_set_id' => $set->id,
                'created_by' => $userId,
                'document_type' => $data['document_type'],
                'title' => $data['title'],
                'status' => ExecutiveDocumentStatusEnum::DRAFT,
                'work_type_name' => $data['work_type_name'] ?? null,
                'section_name' => $data['section_name'] ?? null,
                'completed_work_id' => $data['completed_work_id'] ?? null,
                'inspection_date' => $data['inspection_date'] ?? null,
                'participants' => $data['participants'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            if (!empty($data['initial_version'])) {
                $this->addVersion($document, $userId, $data['initial_version']);
            }

            return $document->fresh(self::DOCUMENT_RELATIONS);
        });
    }

    public function addVersion(ExecutiveDocument $document, int $userId, array $data): ExecutiveDocumentVersion
    {
        if ($document->documentSet?->status === ExecutiveDocumentStatusEnum::TRANSMITTED) {
            throw new DomainException(trans_message('executive_documentation.errors.version_locked_after_transmit'));
        }

        $fileUrl = $data['file_url'] ?? null;
        if (($data['file'] ?? null) instanceof UploadedFile) {
            $organization = Organization::query()->find($document->organization_id);
            $uploadedPath = $this->fileService->upload(
                $data['file'],
                "executive-documentation/project-{$document->project_id}/set-{$document->document_set_id}",
                null,
                'private',
                $organization
            );

            if ($uploadedPath === false) {
                throw new DomainException(trans_message('executive_documentation.errors.version_file_upload_failed'));
            }

            $fileUrl = $uploadedPath;
        }

        if (!is_string($fileUrl) || $fileUrl === '') {
            throw new DomainException(trans_message('executive_documentation.errors.version_file_required'));
        }

        return $document->versions()->create([
            'organization_id' => $document->organization_id,
            'uploaded_by' => $userId,
            'version_number' => $data['version_number'],
            'file_url' => $fileUrl,
            'comment' => $data['comment'] ?? null,
            'uploaded_at' => $data['uploaded_at'] ?? now(),
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function submit(ExecutiveDocument $document, int $userId, ?string $comment = null): ExecutiveDocument
    {
        if (!in_array($document->status, [ExecutiveDocumentStatusEnum::DRAFT, ExecutiveDocumentStatusEnum::REMARKS], true)) {
            throw new DomainException(trans_message('executive_documentation.errors.submit_invalid_status'));
        }

        $document->update([
            'status' => ExecutiveDocumentStatusEnum::UNDER_REVIEW,
            'submitted_at' => now(),
            'metadata' => array_merge($document->metadata ?? [], ['last_submit_comment' => $comment]),
        ]);

        return $document->fresh(self::DOCUMENT_RELATIONS);
    }

    public function addRemark(ExecutiveDocument $document, int $userId, array $data): ExecutiveDocumentRemark
    {
        if ($document->status !== ExecutiveDocumentStatusEnum::UNDER_REVIEW) {
            throw new DomainException(trans_message('executive_documentation.errors.remark_invalid_status'));
        }

        $remark = $document->remarks()->create([
            'organization_id' => $document->organization_id,
            'created_by' => $userId,
            'body' => $data['body'],
            'severity' => $data['severity'] ?? 'major',
            'status' => ExecutiveRemarkStatusEnum::OPEN,
        ]);

        $document->update(['status' => ExecutiveDocumentStatusEnum::REMARKS]);

        return $remark->fresh(['document']);
    }

    public function addCustomerRemark(ExecutiveDocument $document, int $userId, array $data): ExecutiveDocumentRemark
    {
        $document->loadMissing('documentSet');

        if ($document->documentSet?->status !== ExecutiveDocumentStatusEnum::TRANSMITTED) {
            throw new DomainException(trans_message('executive_documentation.errors.customer_remark_requires_transmitted_set'));
        }

        return $document->remarks()->create([
            'organization_id' => $document->organization_id,
            'created_by' => $userId,
            'body' => $data['body'],
            'severity' => $data['severity'] ?? 'major',
            'status' => ExecutiveRemarkStatusEnum::OPEN,
            'metadata' => ['source' => 'customer'],
        ])->fresh(['document']);
    }

    public function resolveRemark(ExecutiveDocumentRemark $remark, int $userId, string $comment): ExecutiveDocumentRemark
    {
        $remark->update([
            'status' => ExecutiveRemarkStatusEnum::RESOLVED,
            'resolved_by' => $userId,
            'resolution_comment' => $comment,
            'resolved_at' => now(),
        ]);

        $document = $remark->document;
        if ($document->openRemarks()->count() === 0) {
            $document->update(['status' => ExecutiveDocumentStatusEnum::UNDER_REVIEW]);
        }

        return $remark->fresh(['document']);
    }

    public function approve(ExecutiveDocument $document, int $userId, ?string $comment = null): ExecutiveDocument
    {
        if (!in_array($document->status, [ExecutiveDocumentStatusEnum::UNDER_REVIEW, ExecutiveDocumentStatusEnum::REMARKS], true)) {
            throw new DomainException(trans_message('executive_documentation.errors.approve_invalid_status'));
        }

        if ($document->openRemarks()->exists()) {
            throw new DomainException(trans_message('executive_documentation.errors.open_remarks_block_approval'));
        }

        $document->update([
            'status' => ExecutiveDocumentStatusEnum::APPROVED,
            'approved_at' => now(),
            'metadata' => array_merge($document->metadata ?? [], [
                'approved_by' => $userId,
                'approval_comment' => $comment,
            ]),
        ]);

        return $document->fresh(self::DOCUMENT_RELATIONS);
    }

    public function transmit(ExecutiveDocumentSet $set, int $userId, array $data): ExecutiveDocumentSet
    {
        $set->loadMissing('documents');

        if ($set->documents->isEmpty()) {
            throw new DomainException(trans_message('executive_documentation.errors.transmit_without_documents'));
        }

        $notApproved = $set->documents->contains(
            static fn (ExecutiveDocument $document): bool => $document->status !== ExecutiveDocumentStatusEnum::APPROVED
        );

        if ($notApproved) {
            throw new DomainException(trans_message('executive_documentation.errors.transmit_requires_approved_documents'));
        }

        return DB::transaction(function () use ($set, $userId, $data): ExecutiveDocumentSet {
            $set->update([
                'status' => ExecutiveDocumentStatusEnum::TRANSMITTED,
                'transmitted_at' => now(),
            ]);

            ExecutiveDocumentTransmittal::query()->create([
                'organization_id' => $set->organization_id,
                'document_set_id' => $set->id,
                'transmitted_by' => $userId,
                'transmittal_number' => $data['transmittal_number'],
                'comment' => $data['comment'] ?? null,
                'transmitted_at' => now(),
                'metadata' => $data['metadata'] ?? null,
            ]);

            $set->documents()->update(['status' => ExecutiveDocumentStatusEnum::TRANSMITTED]);

            return $set->fresh(self::SET_RELATIONS);
        });
    }

    public function acknowledgeTransmittal(ExecutiveDocumentSet $set, int $userId, ?string $comment = null): ExecutiveDocumentSet
    {
        $set->loadMissing('transmittal');

        if ($set->status !== ExecutiveDocumentStatusEnum::TRANSMITTED || $set->transmittal === null) {
            throw new DomainException(trans_message('executive_documentation.errors.acknowledge_requires_transmitted_set'));
        }

        $set->transmittal->update([
            'acknowledged_by' => $userId,
            'acknowledgement_comment' => $comment,
            'acknowledged_at' => now(),
        ]);

        return $set->fresh(self::SET_RELATIONS);
    }

    public function deleteVersion(ExecutiveDocument $document, ExecutiveDocumentVersion $version): void
    {
        $document->loadMissing('documentSet');

        if ($document->documentSet?->status === ExecutiveDocumentStatusEnum::TRANSMITTED) {
            throw new DomainException(trans_message('executive_documentation.errors.version_locked_after_transmit'));
        }

        $version->delete();
    }

    public function findDocument(int $id, int $organizationId): ?ExecutiveDocument
    {
        return ExecutiveDocument::forOrganization($organizationId)
            ->with(self::DOCUMENT_RELATIONS)
            ->find($id);
    }

    public function findRemark(int $id, int $organizationId): ?ExecutiveDocumentRemark
    {
        return ExecutiveDocumentRemark::query()
            ->where('organization_id', $organizationId)
            ->with('document')
            ->find($id);
    }

    private function assertProjectBelongsToOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('executive_documentation.errors.project_not_found'));
        }
    }
}
