<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveRemarkStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRelation;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRemark;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\WorkType;
use App\Services\Storage\FileService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ExecutiveDocumentationService
{
    private const SET_RELATIONS = [
        'project',
        'documents.versions',
        'documents.remarks',
        'documents.workType',
        'documents.journalEntry',
        'documents.relations',
        'transmittal',
    ];

    private const DOCUMENT_RELATIONS = [
        'documentSet',
        'project',
        'versions',
        'remarks',
        'workType',
        'journalEntry',
        'relations',
    ];

    private const EXECUTIVE_WORK_TYPE_CODES = [
        'reinforcement_works',
        'concrete_works',
        'pile_well_drilling',
        'ventilation_works',
        'internal_engineering_systems',
        'reinforced_concrete_works',
        'pile_driving',
        'structure_and_weld_protection',
        'earthworks',
        'roofing_works',
        'low_current_systems_installation',
        'building_structures_installation',
        'pool_bowl_installation',
        'external_engineering_networks',
        'general_construction_works',
        'fireproof_coating',
        'finishing_works',
        'preparatory_works',
        'fire_alarm_and_extinguishing',
        'other',
        'commissioning_works',
        'welding_works',
        'facade_works',
        'electrical_networks_and_communication_lines',
        'electrical_installation_works',
    ];

    public function __construct(
        private readonly ExecutiveDocumentNumberGenerator $numberGenerator,
        private readonly FileService $fileService,
        private readonly ExecutiveDocumentProfileRegistry $profileRegistry,
        private readonly ExecutiveDocumentMutationGuard $mutationGuard,
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
        $createdVersion = null;
        try {
            return DB::transaction(function () use ($set, $userId, $data, &$createdVersion): ExecutiveDocument {
                $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($set->id);
                if ($set->status === ExecutiveDocumentStatusEnum::TRANSMITTED) {
                    throw new DomainException(trans_message('executive_documentation.errors.transmitted_set_locked'));
                }
                $document = ExecutiveDocument::query()->create([
                    'organization_id' => $set->organization_id,
                    'project_id' => $set->project_id,
                    'document_set_id' => $set->id,
                    'created_by' => $userId,
                    'document_type' => $data['document_type'],
                    'title' => $data['title'],
                    'status' => ExecutiveDocumentStatusEnum::DRAFT,
                    'work_type_id' => $data['work_type_id'] ?? null,
                    'work_type_name' => $data['work_type_name'] ?? null,
                    'section_name' => $data['section_name'] ?? null,
                    'completed_work_id' => $data['completed_work_id'] ?? null,
                    'document_date' => $data['document_date'] ?? $data['inspection_date'] ?? $this->documentDateFromInitialVersion($data),
                    'copies_count' => $data['copies_count'] ?? null,
                    'form_variant' => $data['form_variant'] ?? null,
                    'journal_entry_id' => $data['journal_entry_id'] ?? null,
                    'inspection_date' => $data['inspection_date'] ?? null,
                    'participants' => $data['participants'] ?? null,
                    'profile_data' => $data['profile_data'] ?? null,
                    'signatories' => $data['signatories'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                ]);

                if (!empty($data['initial_version'])) {
                    $createdVersion = $this->createVersion($document, $userId, $data['initial_version'], 'executive-documentation.create');
                }

                $this->syncRelations($document, $data['relations'] ?? []);

                return $document->fresh(self::DOCUMENT_RELATIONS);
            });
        } catch (\Throwable $exception) {
            if ($createdVersion !== null) {
                $this->removeFailedUpload($createdVersion->file_url, Organization::query()->find($set->organization_id), (int) $createdVersion->document_id);
            }
            throw $exception;
        }
    }

    public function addVersion(ExecutiveDocument $document, int $userId, array $data): ExecutiveDocumentVersion
    {
        return $this->createVersion($document, $userId, $data, 'executive-documentation.edit');
    }

    public function updateDraft(ExecutiveDocument $document, int $userId, array $data): ExecutiveDocument
    {
        return DB::transaction(function () use ($document, $userId, $data): ExecutiveDocument {
            $lockedDocument = ExecutiveDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->mutationGuard->assertActor($lockedDocument, $userId, 'executive-documentation.edit');
            $expectedVersionId = (int) ($data['expected_version_id'] ?? 0);
            $latest = $lockedDocument->versions()->latest('id')->first();
            if ($expectedVersionId <= 0 || $latest === null || $latest->id !== $expectedVersionId) {
                throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
            }
            if ($lockedDocument->status !== ExecutiveDocumentStatusEnum::DRAFT
                || $latest->status !== 'draft' || empty($latest->content_hash)
                || $latest->submitted_at !== null || $latest->approved_at !== null || $latest->transmitted_at !== null) {
                throw new DomainException(trans_message('executive_documentation.errors.draft_locked'));
            }
            $revision = (int) ($latest->metadata['draft_revision'] ?? 0);
            if (! array_key_exists('expected_revision', $data) || (int) $data['expected_revision'] !== $revision) {
                throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
            }
            $lockedDocument->fill(array_intersect_key($data, array_flip([
                'title', 'section_name', 'document_date', 'inspection_date', 'participants', 'profile_data',
                'signatories', 'metadata', 'work_type_id', 'work_type_name', 'completed_work_id', 'journal_entry_id',
            ])));
            $this->mutationGuard->assertReferences($lockedDocument);
            $lockedDocument->save();
            $latest->update([
                'metadata' => array_merge($latest->metadata ?? [], ['draft_revision' => $revision + 1]),
                'profile_snapshot' => $lockedDocument->profile_data,
                'basis_snapshot' => $this->versionBasis($lockedDocument),
            ]);
            return $lockedDocument->fresh(self::DOCUMENT_RELATIONS);
        });
    }

    public function submit(ExecutiveDocument $document, int $userId, ?string $comment = null, ?int $versionId = null): ExecutiveDocument
    {
        return DB::transaction(function () use ($document, $userId, $comment, $versionId): ExecutiveDocument {
            $lockedDocument = ExecutiveDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->mutationGuard->assertActor($lockedDocument, $userId, 'executive-documentation.submit');
            if (!in_array($lockedDocument->status, [ExecutiveDocumentStatusEnum::DRAFT, ExecutiveDocumentStatusEnum::REMARKS], true)) {
                throw new DomainException(trans_message('executive_documentation.errors.submit_invalid_status'));
            }
            $version = $this->versionForAction($lockedDocument, $versionId);
            $version->update(['status' => 'under_review', 'submitted_at' => now()]);
            $lockedDocument->update([
                'status' => ExecutiveDocumentStatusEnum::UNDER_REVIEW,
                'submitted_at' => now(),
                'metadata' => array_merge($lockedDocument->metadata ?? [], ['last_submit_comment' => $comment, 'submitted_by' => $userId]),
            ]);
            return $lockedDocument->fresh(self::DOCUMENT_RELATIONS);
        });
    }

    public function addRemark(ExecutiveDocument $document, int $userId, array $data): ExecutiveDocumentRemark
    {
        return DB::transaction(function () use ($document, $userId, $data): ExecutiveDocumentRemark {
            $lockedDocument = ExecutiveDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->mutationGuard->assertActor($lockedDocument, $userId, 'executive-documentation.review');
            if (!in_array($lockedDocument->status, [ExecutiveDocumentStatusEnum::UNDER_REVIEW, ExecutiveDocumentStatusEnum::REMARKS], true)) {
                throw new DomainException(trans_message('executive_documentation.errors.remark_invalid_status'));
            }
            $version = $this->versionForAction($lockedDocument, isset($data['version_id']) ? (int) $data['version_id'] : null);
            $remark = $lockedDocument->remarks()->create([
                'organization_id' => $lockedDocument->organization_id,
                'version_id' => $version->id,
                'created_by' => $userId,
                'body' => $data['body'],
                'severity' => $data['severity'] ?? 'major',
                'status' => ExecutiveRemarkStatusEnum::OPEN,
            ]);
            $lockedDocument->update(['status' => ExecutiveDocumentStatusEnum::REMARKS]);
            return $remark->fresh(['document']);
        });
    }

    public function addCustomerRemark(ExecutiveDocument $document, int $userId, array $data): ExecutiveDocumentRemark
    {
        if (empty($data['transmittal_id']) || empty($data['version_id'])) {
            throw new DomainException(trans_message('executive_documentation.errors.transmittal_client_upgrade'));
        }
        return app(ExecutiveTransmittalService::class)->remark((int) $document->id, $userId, $data);
    }

    public function resolveRemark(ExecutiveDocumentRemark $remark, int $userId, string $comment, ?string $response = null, ?int $expectedRevision = null): ExecutiveDocumentRemark
    {
        return $this->reviewRemark($remark, $userId, 'accept', $comment, null, $expectedRevision);
    }

    public function approve(ExecutiveDocument $document, int $userId, ?string $comment = null, ?int $versionId = null): ExecutiveDocument
    {
        return DB::transaction(function () use ($document, $userId, $comment, $versionId): ExecutiveDocument {
            $lockedDocument = ExecutiveDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->mutationGuard->assertActor($lockedDocument, $userId, 'executive-documentation.approve');
            if (!in_array($lockedDocument->status, [ExecutiveDocumentStatusEnum::UNDER_REVIEW, ExecutiveDocumentStatusEnum::REMARKS], true)) {
                throw new DomainException(trans_message('executive_documentation.errors.approve_invalid_status'));
            }
            $version = $this->versionForAction($lockedDocument, $versionId);
            if ($lockedDocument->remarks()->whereIn('status', ['open', 'answered', 'returned'])->exists()) {
                throw new DomainException(trans_message('executive_documentation.errors.open_remarks_block_approval'));
            }
            $version->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);
            $lockedDocument->update([
                'status' => ExecutiveDocumentStatusEnum::APPROVED,
                'approved_at' => now(),
                'metadata' => array_merge($lockedDocument->metadata ?? [], [
                    'approved_by' => $userId,
                    'approval_comment' => $comment,
                ]),
            ]);
            return $lockedDocument->fresh(self::DOCUMENT_RELATIONS);
        });
    }

    public function answerRemark(ExecutiveDocumentRemark $remark, int $userId, string $response, ?int $expectedVersionId = null, ?int $responseVersionId = null, ?int $expectedRevision = null): ExecutiveDocumentRemark
    {
        return DB::transaction(function () use ($remark, $userId, $response, $expectedVersionId, $responseVersionId, $expectedRevision): ExecutiveDocumentRemark {
            $remarkDocumentId = ExecutiveDocumentRemark::query()->whereKey($remark->id)->value('document_id');
            $document = ExecutiveDocument::query()->lockForUpdate()->findOrFail($remarkDocumentId);
            $this->mutationGuard->assertActor($document, $userId, 'executive-documentation.edit');
            if (trim($response) === '' || $document->status === ExecutiveDocumentStatusEnum::ARCHIVED) {
                throw new DomainException(trans_message('executive_documentation.errors.review_input_invalid'));
            }
            $lockedRemark = ExecutiveDocumentRemark::query()->whereKey($remark->id)->lockForUpdate()->firstOrFail();
            if ($expectedRevision !== null && $expectedRevision !== count($lockedRemark->metadata['review_history'] ?? [])) {
                throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
            }
            if ($expectedVersionId !== null && (int) $lockedRemark->version_id !== $expectedVersionId) {
                throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
            }
            if (!in_array($lockedRemark->status, [ExecutiveRemarkStatusEnum::OPEN, ExecutiveRemarkStatusEnum::RETURNED], true)) {
                throw new DomainException(trans_message('executive_documentation.errors.remark_answer_invalid_status'));
            }
            if ($lockedRemark->version_id === null || !$document->versions()->whereKey($lockedRemark->version_id)->exists()) {
                throw new DomainException(trans_message('executive_documentation.errors.version_not_found'));
            }
            if ($responseVersionId !== null && ($responseVersionId <= (int) $lockedRemark->version_id || !$document->versions()->whereKey($responseVersionId)->whereNotNull('content_hash')->exists())) {
                throw new DomainException(trans_message('executive_documentation.errors.version_not_found'));
            }
            $metadata = $lockedRemark->metadata ?? [];
            $metadata['response_version_id'] = $responseVersionId;
            $metadata['review_history'][] = ['action' => 'answer', 'actor_id' => $userId, 'at' => now()->toIso8601String(), 'comment' => $response, 'response_version_id' => $responseVersionId];
            $lockedRemark->update([
                'metadata' => $metadata,
                'review_comment' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'status' => ExecutiveRemarkStatusEnum::ANSWERED,
                'answered_by' => $userId,
                'answered_at' => now(),
                'response' => $response,
            ]);
            return $lockedRemark->fresh(['document', 'version']);
        });
    }

    public function reviewRemark(ExecutiveDocumentRemark $remark, int $userId, string $decision, string $comment, ?int $expectedVersionId = null, ?int $expectedRevision = null): ExecutiveDocumentRemark
    {
        return DB::transaction(function () use ($remark, $userId, $decision, $comment, $expectedVersionId, $expectedRevision): ExecutiveDocumentRemark {
            $remarkDocumentId = ExecutiveDocumentRemark::query()->whereKey($remark->id)->value('document_id');
            $document = ExecutiveDocument::query()->lockForUpdate()->findOrFail($remarkDocumentId);
            $this->mutationGuard->assertActor($document, $userId, 'executive-documentation.review');
            if (trim($comment) === '' || $document->status === ExecutiveDocumentStatusEnum::ARCHIVED) {
                throw new DomainException(trans_message('executive_documentation.errors.review_input_invalid'));
            }
            $lockedRemark = ExecutiveDocumentRemark::query()->whereKey($remark->id)->lockForUpdate()->firstOrFail();
            if ($expectedRevision !== null && $expectedRevision !== count($lockedRemark->metadata['review_history'] ?? [])) {
                throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
            }
            if ($expectedVersionId !== null && (int) $lockedRemark->version_id !== $expectedVersionId) {
                throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
            }
            if ((int) $lockedRemark->answered_by === $userId) {
                throw new DomainException(trans_message('executive_documentation.errors.remark_self_review_forbidden'));
            }
            if ($lockedRemark->status !== ExecutiveRemarkStatusEnum::ANSWERED) {
                throw new DomainException(trans_message('executive_documentation.errors.remark_review_requires_answer'));
            }
            $version = $document->versions()->whereKey($lockedRemark->version_id)->first();
            if ($version === null) {
                throw new DomainException(trans_message('executive_documentation.errors.version_not_found'));
            }
            if (!in_array($decision, ['accept', 'return'], true)) {
                throw new DomainException(trans_message('executive_documentation.errors.remark_review_decision_invalid'));
            }
            $metadata = $lockedRemark->metadata ?? [];
            $metadata['review_history'][] = ['action' => $decision, 'actor_id' => $userId, 'at' => now()->toIso8601String(), 'comment' => $comment, 'response_version_id' => $metadata['response_version_id'] ?? null];
            $lockedRemark->update([
                'metadata' => $metadata,
                'resolution_comment' => $decision === 'accept' ? $comment : null,
                'status' => $decision === 'accept' ? ExecutiveRemarkStatusEnum::RESOLVED : ExecutiveRemarkStatusEnum::RETURNED,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'review_comment' => $comment,
                'resolved_by' => $decision === 'accept' ? $userId : null,
                'resolved_at' => $decision === 'accept' ? now() : null,
            ]);
            if ((int) $document->versions()->value('id') === (int) $version->id
                && $document->status === ExecutiveDocumentStatusEnum::REMARKS
                && $version->status === 'under_review' && $decision === 'accept'
                && !$document->openRemarks()->exists()) {
                $version->update(['status' => 'under_review']);
                $document->update(['status' => ExecutiveDocumentStatusEnum::UNDER_REVIEW]);
            }
            return $lockedRemark->fresh(['document', 'version']);
        });
    }

    public function reject(ExecutiveDocument $document, int $userId, string $comment, ?int $versionId = null): ExecutiveDocument
    {
        return DB::transaction(function () use ($document, $userId, $comment, $versionId): ExecutiveDocument {
            $lockedDocument = ExecutiveDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->mutationGuard->assertActor($lockedDocument, $userId, 'executive-documentation.review');
            if (trim($comment) === '' || !in_array($lockedDocument->status, [ExecutiveDocumentStatusEnum::UNDER_REVIEW, ExecutiveDocumentStatusEnum::REMARKS], true)) {
                throw new DomainException(trans_message('executive_documentation.errors.review_input_invalid'));
            }
            $version = $this->versionForAction($lockedDocument, $versionId);
            $metadata = $version->metadata ?? [];
            $metadata['review_history'][] = ['action' => 'reject', 'actor_id' => $userId, 'at' => now()->toIso8601String(), 'comment' => $comment];
            $version->update(['status' => 'rejected', 'metadata' => $metadata]);
            $lockedDocument->update(['status' => ExecutiveDocumentStatusEnum::REJECTED]);
            return $lockedDocument->fresh(self::DOCUMENT_RELATIONS);
        });
    }

    public function transmit(ExecutiveDocumentSet $set, int $userId, array $data): ExecutiveDocumentSet
    {
        return DB::transaction(function () use ($set, $userId, $data): ExecutiveDocumentSet {
        $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($set->id);
        $documents = ExecutiveDocument::query()
            ->where('document_set_id', $set->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($documents as $document) {
            $this->mutationGuard->assertActor($document, $userId, 'executive-documentation.approve');
        }
        $documents->load(['versions', 'remarks', 'workType', 'journalEntry']);
        $set->setRelation('documents', $documents);
        $operationKey = (string) ($data['operation_key'] ?? 'transmittal:'.$set->id.':'.(string) $data['transmittal_number']);
        $existingTransmittal = ExecutiveDocumentTransmittal::query()
            ->where('organization_id', $set->organization_id)
            ->where('document_set_id', $set->id)
            ->where('operation_key', $operationKey)
            ->first();
        if ($existingTransmittal !== null) {
            $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($existingTransmittal->operation_hash !== $requestHash) {
                throw new DomainException(trans_message('executive_documentation.errors.operation_conflict'));
            }
            return $set->fresh(self::SET_RELATIONS)->setRelation('transmittal', $existingTransmittal);
        }

        if ($set->documents->isEmpty()) {
            throw new DomainException(trans_message('executive_documentation.errors.transmit_without_documents'));
        }

        $notApproved = $set->documents->contains(
            static fn (ExecutiveDocument $document): bool => !in_array($document->status, [ExecutiveDocumentStatusEnum::APPROVED, ExecutiveDocumentStatusEnum::TRANSMITTED], true)
        );

        if ($notApproved) {
            throw new DomainException(trans_message('executive_documentation.errors.transmit_requires_approved_documents'));
        }

        if ($this->setHasIncompleteDocuments($set)) {
            throw new DomainException(trans_message('executive_documentation.errors.transmit_requires_complete_documents'));
        }

            $documentsManifest = $set->documents->map(static function (ExecutiveDocument $document): array {
                $version = $document->versions->sortByDesc('id')->first();
                if ($version === null || !in_array($version->status, ['approved', 'transmitted'], true) || empty($version->content_hash)) {
                    throw new DomainException(trans_message('executive_documentation.errors.transmit_requires_approved_documents'));
                }
                return [
                    'document_id' => $document->id,
                    'title' => $version->basis_snapshot['document']['title'] ?? $document->title,
                    'document_type' => $document->document_type->value,
                    'document_type_label' => $document->document_type->label(),
                    'version_id' => $version->id,
                    'content_hash' => $version->content_hash,
                    'version_number' => $version->version_number,
                    'file_url' => $version->file_url,
                    'profile_snapshot' => $version->profile_snapshot,
                    'basis_snapshot' => $version->basis_snapshot,
                ];
            })->values()->all();
            if (isset($data['expected_versions'])) {
                $expected = collect($data['expected_versions'])->mapWithKeys(fn ($row) => [(int) $row['document_id'] => (int) $row['version_id']])->sortKeys()->all();
                $actual = collect($documentsManifest)->mapWithKeys(fn ($row) => [(int) $row['document_id'] => (int) $row['version_id']])->sortKeys()->all();
                if ($expected !== $actual || count($data['expected_versions']) !== count($actual)) {
                    throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
                }
            }
            $recipient = app(\App\Services\Project\ProjectCustomerResolverService::class)->resolve($set->project);
            if (isset($data['recipient']['organization_id']) && (int) $data['recipient']['organization_id'] !== (int) $recipient['id']) {
                throw new DomainException(trans_message('executive_documentation.errors.recipient_conflict'));
            }
            $previous = $set->transmittal()->first();
            $manifest = [
                'set' => $set->only(['id', 'set_number', 'title', 'project_id', 'stage_name', 'zone_name']),
                'project' => ['id' => $set->project_id, 'name' => $set->project->name],
                'sender' => ['user_id' => $userId, 'organization_id' => $set->organization_id, 'name' => $set->organization->name],
                'recipient' => ['organization_id' => $recipient['id'], 'name' => $recipient['name'], 'source' => $recipient['source']],
                'documents' => $documentsManifest,
            ];
            $operationHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $set->update([
                'status' => ExecutiveDocumentStatusEnum::TRANSMITTED,
                'transmitted_at' => now(),
            ]);

            $transmittal = ExecutiveDocumentTransmittal::query()->create([
                'organization_id' => $set->organization_id,
                'document_set_id' => $set->id,
                'transmitted_by' => $userId,
                'transmittal_number' => $data['transmittal_number'],
                'comment' => $data['comment'] ?? null,
                'transmitted_at' => now(),
                'metadata' => ['source_metadata' => $data['metadata'] ?? null],
                'manifest' => $manifest,
                'manifest_hash' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
                'status' => 'sent',
                'previous_transmittal_id' => $previous?->id,
                'operation_key' => $operationKey,
                'operation_hash' => $operationHash,
            ]);

            $manifestVersionIds = collect($documentsManifest)->pluck('version_id')->all();
            $set->documents()->whereIn('id', collect($documentsManifest)->pluck('document_id')->all())
                ->update(['status' => ExecutiveDocumentStatusEnum::TRANSMITTED]);
            $set->documents->each(static function (ExecutiveDocument $document) use ($manifestVersionIds): void {
                $document->versions()->whereIn('id', $manifestVersionIds)->where('status', 'approved')->update([
                    'status' => 'transmitted',
                    'transmitted_at' => now(),
                ]);
            });

            return $set->fresh(self::SET_RELATIONS);
        });
    }

    public function acknowledgeTransmittal(ExecutiveDocumentSet $set, int $userId, ?string $comment = null): ExecutiveDocumentSet
    {
        throw new DomainException(trans_message('executive_documentation.errors.transmittal_client_upgrade'));
    }

    public function deleteVersion(ExecutiveDocument $document, ExecutiveDocumentVersion $version, int $userId): void
    {
        DB::transaction(function () use ($document, $version, $userId): void {
            $lockedDocument = ExecutiveDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->mutationGuard->assertActor($lockedDocument, $userId, 'executive-documentation.delete');
            $lockedVersion = $lockedDocument->versions()->whereKey($version->id)->first();
            if ($lockedVersion === null) {
                throw new BusinessLogicException(trans_message('executive_documentation.errors.version_not_found'), 404);
            }
            $lockedDocument->loadMissing('documentSet');
            if ($lockedVersion->status !== 'draft' || empty($lockedVersion->content_hash)
                || $lockedVersion->submitted_at !== null || $lockedVersion->approved_at !== null || $lockedVersion->transmitted_at !== null
                || $lockedDocument->status !== ExecutiveDocumentStatusEnum::DRAFT) {
                throw new DomainException(trans_message('executive_documentation.errors.version_locked_after_transmit'));
            }
            $lockedVersion->delete();
        });
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

    /**
     * @return Collection<int, WorkType>
     */
    public function ensureExecutiveWorkTypes(int $organizationId): Collection
    {
        foreach (self::EXECUTIVE_WORK_TYPE_CODES as $sortOrder => $code) {
            $workType = WorkType::withTrashed()
                ->where('organization_id', $organizationId)
                ->where('category', 'Исполнительная документация')
                ->where('code', $code)
                ->first();

            $attributes = [
                'name' => trans_message("executive_documentation.work_types.{$code}"),
                'description' => trans_message('executive_documentation.work_type_description'),
                'category' => 'Исполнительная документация',
                'additional_properties' => [
                    'contexts' => ['executive_documentation'],
                    'source' => 'pto',
                    'sort_order' => $sortOrder + 1,
                ],
                'is_active' => true,
            ];

            if ($workType === null) {
                WorkType::query()->create([
                    'organization_id' => $organizationId,
                    'code' => $code,
                    ...$attributes,
                ]);

                continue;
            }

            if ($workType->trashed()) {
                $workType->restore();
            }

            $workType->fill($attributes)->save();
        }

        return WorkType::query()
            ->where('organization_id', $organizationId)
            ->where('category', 'Исполнительная документация')
            ->whereIn('code', self::EXECUTIVE_WORK_TYPE_CODES)
            ->orderByRaw('CASE code ' . implode(' ', array_map(
                static fn (string $code, int $index): string => "WHEN '{$code}' THEN {$index}",
                self::EXECUTIVE_WORK_TYPE_CODES,
                array_keys(self::EXECUTIVE_WORK_TYPE_CODES)
            )) . ' END')
            ->get();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function documentDateFromInitialVersion(array $data): string
    {
        return Carbon::parse($data['initial_version']['uploaded_at'] ?? now())->toDateString();
    }

    /**
     * @param array<int, array<string, mixed>> $relations
     */
    private function syncRelations(ExecutiveDocument $document, array $relations): void
    {
        if ($relations === []) {
            return;
        }

        $document->relations()->delete();

        foreach ($relations as $relation) {
            ExecutiveDocumentRelation::query()->create([
                'organization_id' => $document->organization_id,
                'document_id' => $document->id,
                'relation_type' => $relation['relation_type'],
                'target_type' => $relation['target_type'],
                'target_id' => (int) $relation['target_id'],
                'label' => $relation['label'] ?? null,
                'metadata' => $relation['metadata'] ?? null,
            ]);
        }
    }

    private function setHasIncompleteDocuments(ExecutiveDocumentSet $set): bool
    {
        return $set->documents->contains(function (ExecutiveDocument $document): bool {
            $profile = $this->profileRegistry->find($document->document_type->value);

            if (($profile['requires_work_type'] ?? false) === true && $document->work_type_id === null) {
                return true;
            }

            if (($profile['requires_journal_entry'] ?? false) === true && $document->journal_entry_id === null) {
                return true;
            }

            if ($profile !== null && $this->profileRegistry->missingRequiredFields($document->document_type->value, $document->profile_data ?? []) !== []) {
                return true;
            }

            return $document->versions->isEmpty() || $document->openRemarks()->exists();
        });
    }

    private function versionForAction(ExecutiveDocument $document, ?int $versionId): ExecutiveDocumentVersion
    {
        $version = $document->versions()->reorder()->orderByDesc('id')->first();
        if ($version === null) {
            throw new DomainException(trans_message('executive_documentation.errors.version_not_found'));
        }
        if ($versionId !== null && (int) $version->id !== $versionId) {
            throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
        }
        return $version;
    }

    private function createVersion(ExecutiveDocument $document, int $userId, array $data, string $permission): ExecutiveDocumentVersion
    {
        $uploadedPath = null;
        $organization = null;
        try {
            return DB::transaction(function () use ($document, $userId, $data, $permission, &$uploadedPath, &$organization): ExecutiveDocumentVersion {
                $lockedDocument = ExecutiveDocument::query()->lockForUpdate()->findOrFail($document->id);
                $this->mutationGuard->assertActor($lockedDocument, $userId, $permission);
                $latestVersionId = $lockedDocument->versions()->value('id');
                $file = $data['file'] ?? null;
                if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() <= 0) {
                    throw new DomainException(trans_message('executive_documentation.errors.version_file_required'));
                }
                if (isset($data['profile_data'], $data['profile_snapshot']) && $data['profile_data'] !== $data['profile_snapshot']) {
                    throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
                }
                $operationKey = $data['operation_key'] ?? null;
                $requestedProfile = $data['profile_data'] ?? $data['profile_snapshot'] ?? null;
                $contentHash = hash_file('sha256', $file->getRealPath());
                $operationHash = hash('sha256', json_encode([
                    'document_id' => $lockedDocument->id,
                    'expected_version_id' => (int) ($data['expected_version_id'] ?? 0),
                    'version_number' => $data['version_number'],
                    'comment' => $data['comment'] ?? null,
                    'content_hash' => $contentHash,
                    'profile_snapshot' => $requestedProfile,
                    'basis_snapshot' => $data['basis_snapshot'] ?? null,
                    'metadata' => $data['metadata'] ?? null,
                    'uploaded_at' => $data['uploaded_at'] ?? null,
                ], JSON_THROW_ON_ERROR));
                if ($operationKey !== null) {
                    $existing = $lockedDocument->versions()->withTrashed()->where('operation_key', $operationKey)->first();
                    if ($existing !== null) {
                        if ($existing->trashed() || $existing->operation_hash !== $operationHash) {
                            throw new DomainException(trans_message('executive_documentation.errors.operation_conflict'));
                        }
                        return $existing;
                    }
                }
                if (isset($data['expected_version_id']) && (int) $data['expected_version_id'] !== (int) $latestVersionId) {
                    throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
                }
                $this->mutationGuard->assertReferences($lockedDocument);
                $basisSnapshot = $this->versionBasis($lockedDocument);
                foreach ($data['basis_snapshot'] ?? [] as $key => $value) {
                    if (! in_array($key, ['project_id', 'completed_work_id', 'journal_entry_id'], true)
                        || ($value === null ? null : (int) $value) !== ($basisSnapshot[$key] === null ? null : (int) $basisSnapshot[$key])) {
                        throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
                    }
                }
                if ($lockedDocument->versions()->withTrashed()->where('version_number', $data['version_number'])->exists()) {
                    throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
                }
                $organization = Organization::query()->findOrFail($lockedDocument->organization_id);
                $uploadedPath = $this->fileService->upload(
                    $file,
                    "executive-documentation/project-{$lockedDocument->project_id}/set-{$lockedDocument->document_set_id}",
                    null,
                    'private',
                    $organization,
                );
                if ($uploadedPath === false) {
                    throw new DomainException(trans_message('executive_documentation.errors.version_file_upload_failed'));
                }
                $profileSnapshot = $requestedProfile ?? $lockedDocument->profile_data;
                $version = $lockedDocument->versions()->create([
                    'organization_id' => $lockedDocument->organization_id,
                    'uploaded_by' => $userId,
                    'status' => 'draft',
                    'version_number' => $data['version_number'],
                    'file_url' => $uploadedPath,
                    'content_hash' => $contentHash,
                    'comment' => $data['comment'] ?? null,
                    'uploaded_at' => $data['uploaded_at'] ?? now(),
                    'metadata' => array_merge($data['metadata'] ?? [], ['draft_revision' => 0]),
                    'profile_snapshot' => $profileSnapshot,
                    'basis_snapshot' => $basisSnapshot,
                    'operation_key' => $operationKey,
                    'operation_hash' => $operationHash,
                ]);
                $lockedDocument->update([
                    'profile_data' => $profileSnapshot,
                    'status' => ExecutiveDocumentStatusEnum::DRAFT,
                    'submitted_at' => null,
                    'approved_at' => null,
                ]);
                return $version;
            });
        } catch (\Throwable $exception) {
            if (is_string($uploadedPath)) {
                $this->removeFailedUpload($uploadedPath, $organization, (int) $document->id);
            }
            throw $exception;
        }
    }

    private function versionBasis(ExecutiveDocument $document): array
    {
        return [
            'project_id' => $document->project_id,
            'completed_work_id' => $document->completed_work_id,
            'journal_entry_id' => $document->journal_entry_id,
            'document' => $document->only([
                'title', 'document_type', 'section_name', 'document_date', 'inspection_date',
                'participants', 'signatories', 'work_type_id', 'work_type_name', 'copies_count', 'form_variant',
            ]),
        ];
    }

    private function removeFailedUpload(string $path, ?Organization $organization, int $documentId): void
    {
        try {
            if (ExecutiveDocumentVersion::withTrashed()->where('file_url', $path)->exists()) {
                return;
            }
        } catch (\Throwable) {
            Log::error('executive_documentation.upload_cleanup_deferred', ['document_id' => $documentId, 'file_path' => $path]);
            return;
        }
        if (! $this->fileService->delete($path, $organization)) {
            Log::error('executive_documentation.upload_cleanup_failed', [
                'document_id' => $documentId,
                'organization_id' => $organization?->id,
                'file_path' => $path,
            ]);
        }
    }
}
