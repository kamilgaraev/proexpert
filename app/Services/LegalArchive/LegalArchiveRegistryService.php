<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Project;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentVersionAttempt;
use App\Services\LegalArchive\Files\LegalDocumentVersionLeaseLost;
use App\Services\LegalArchive\Files\LegalDocumentVersionPersistenceFailed;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use App\Services\LegalArchive\Sources\LegalDocumentCreateLeaseDecision;
use App\Services\LegalArchive\Sources\LegalDocumentCreateRequestFingerprint;
use App\Services\LegalArchive\Sources\LegalDocumentSourceCreateIdentity;
use App\Services\LegalArchive\Sources\LegalDocumentSourceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

use function trans_message;

final class LegalArchiveRegistryService
{
    private const CREATE_LEASE_SECONDS = 1800;

    public function __construct(
        private readonly LegalDocumentFileService $documentFileService,
        private readonly LegalDocumentDownloadService $downloadService,
        private readonly LegalDocumentAudit $audit,
        private readonly LegalDocumentSourceResolver $sourceResolver,
        private readonly LegalDocumentAccessService $access,
        private readonly LegalDocumentProfileRegistry $profiles = new LegalDocumentProfileRegistry,
        private readonly LegalDocumentProfileValidator $profileValidator = new LegalDocumentProfileValidator,
    ) {}

    public function paginate(User $actor, int $organizationId, array $filters): LengthAwarePaginator
    {
        $perPage = max(10, min((int) ($filters['per_page'] ?? 20), 100));

        $sortBy = (string) ($filters['sort_by'] ?? 'document_date');
        $sortDirection = (string) ($filters['sort_direction'] ?? 'desc');

        return $this->baseQuery($actor, $organizationId, $filters)
            ->with(['currentVersion', 'links', 'project:id,name,status,organization_id', 'latestWorkflowInstance.steps'])
            ->withCount(['files', 'signatureRequests', 'signatures'])
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('id', $sortDirection)
            ->paginate($perPage);
    }

    public function summary(User $actor, int $organizationId, array $filters): array
    {
        $query = $this->baseQuery($actor, $organizationId, $filters);

        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'legal_hold' => (clone $query)->where('legal_hold', true)->count(),
            'retention_due' => (clone $query)
                ->where('legal_hold', false)
                ->whereNotNull('retention_until')
                ->where('retention_until', '<=', now()->addDays(30))
                ->count(),
            'by_status' => (clone $query)
                ->select('status', DB::raw('count(*) as aggregate_count'))
                ->groupBy('status')
                ->pluck('aggregate_count', 'status')
                ->all(),
            'by_type' => (clone $query)
                ->select('document_type', DB::raw('count(*) as aggregate_count'))
                ->groupBy('document_type')
                ->pluck('aggregate_count', 'document_type')
                ->all(),
        ];
    }

    public function findForOrganization(int $organizationId, int $documentId): ?LegalArchiveDocument
    {
        return LegalArchiveDocument::query()
            ->with(['currentVersion', 'versions', 'links', 'project:id,name,status,organization_id', 'createdBy:id,name,email'])
            ->forOrganization($organizationId)
            ->find($documentId);
    }

    public function findForAuthorization(int $documentId): ?LegalArchiveDocument
    {
        return $this->detailQuery()->find($documentId);
    }

    public function paginateRecoveries(User $actor, int $organizationId, int $perPage = 20): LengthAwarePaginator
    {
        $query = LegalArchiveDocument::query();
        $this->access->scopeAccessibleQuery($query, $actor, $organizationId);

        return $query
            ->where('created_by_user_id', $actor->id)
            ->where('source_create_status', '!=', 'completed')
            ->with(['currentVersion', 'links'])
            ->orderByDesc('source_create_failed_at')
            ->orderByDesc('id')
            ->paginate(max(10, min($perPage, 100)));
    }

    public function create(int $organizationId, ?int $userId, array $data, ?UploadedFile $file = null): LegalArchiveDocument
    {
        $hasSourceIdentity = isset($data['source_type']) || isset($data['source_id']) || isset($data['source_idempotency_key']);
        $rawOperationKey = $data['create_operation_key'] ?? null;
        if (! $hasSourceIdentity && (! is_string($rawOperationKey) || trim($rawOperationKey) === '' || mb_strlen(trim($rawOperationKey)) > 191)) {
            throw ValidationException::withMessages([
                'create_operation_key' => [trans_message('legal_archive.messages.create_operation_key_required')],
            ]);
        }
        if ($file instanceof UploadedFile) {
            $this->documentFileService->assertUploadAllowed($file);
        }
        $sourceCreateIdentity = $this->sourceCreateIdentity($organizationId, $userId, $data);
        if ($sourceCreateIdentity !== null) {
            $data = $sourceCreateIdentity->normalizeInput($data);
        }
        $requestFingerprint = LegalDocumentCreateRequestFingerprint::fromRequest($organizationId, $userId, $data, $file);
        $operationKey = isset($data['create_operation_key']) ? trim((string) $data['create_operation_key']) : null;
        $attemptToken = (string) Str::uuid();

        try {
            [$document, $documentFile, $replayed, $retryAction, $attemptToken] = DB::transaction(function () use (
                $organizationId,
                $userId,
                $data,
                $file,
                $sourceCreateIdentity,
                $requestFingerprint,
                $operationKey,
                $attemptToken,
            ): array {
                $this->assertProjectBelongsToOrganization($organizationId, $data['primary_project_id'] ?? null);
                $this->sourceResolver->assertOwnedSource($organizationId, $data['source_type'] ?? null, $data['source_id'] ?? null);
                $replay = $this->resolveSourceCreateReplay(
                    $sourceCreateIdentity,
                    $organizationId,
                    $userId,
                    $operationKey,
                    $requestFingerprint,
                    true,
                );
                if ($replay instanceof LegalArchiveDocument) {
                    if ((string) $replay->source_create_status === 'completed') {
                        return [$replay, null, true, null, null];
                    }

                    $documentFile = $replay->files()->where('role', 'primary')->first();
                    $hasReadyVersion = $documentFile instanceof LegalArchiveDocumentFile
                        && $documentFile->versions()->where('processing_status', 'ready')->exists();
                    $this->assertCreateRecoveryInput($replay, $hasReadyVersion, $file);
                    $retryAction = $this->claimCreateAttempt($replay, $attemptToken, $hasReadyVersion);
                    if ($retryAction === 'retry_upload' && ! $documentFile instanceof LegalArchiveDocumentFile) {
                        $documentFile = $this->primaryFile($replay, $organizationId);
                    }
                    $this->recordCreateRecoveryStarted($replay, $userId, $requestFingerprint, $retryAction);

                    return [$replay, $documentFile, false, $retryAction, $attemptToken];
                }

                $payload = $this->documentPayload($organizationId, $userId, $data);
                $payload = [...$payload, ...$this->newAttemptPayload(
                    $attemptToken,
                    $file instanceof UploadedFile ? 'retry_upload' : 'retry_finalize',
                )];
                $payload['source_request_fingerprint'] = $requestFingerprint;
                $payload['create_operation_id'] = (string) Str::uuid();
                $payload['create_operation_key'] = $operationKey;
                $document = LegalArchiveDocument::query()->create($payload);
                $this->replaceLinks($document, $data['links'] ?? []);
                $documentFile = $file instanceof UploadedFile
                    ? $this->primaryFile($document, $organizationId)
                    : null;
                $this->audit->recordForActorId('create_pending', $document, $userId, [
                    'after' => $this->auditSnapshot($document),
                    'source_event_id' => $sourceCreateIdentity?->sourceEventId(),
                    'request_fingerprint' => $requestFingerprint,
                ]);
                if ($userId !== null) {
                    $this->access->bootstrapCreator($document, $userId);
                }

                return [$document, $documentFile, false, $file instanceof UploadedFile ? 'retry_upload' : 'retry_finalize', $attemptToken];
            });
        } catch (QueryException $exception) {
            return $this->retryCreateAfterUniqueCollision(
                $exception,
                $sourceCreateIdentity,
                $organizationId,
                $userId,
                $data,
                $file,
                $operationKey,
                $requestFingerprint,
            );
        }

        if ($replayed) {
            return $this->findForOrganization($organizationId, (int) $document->id) ?? $document;
        }

        if ($retryAction === 'retry_upload' && $file instanceof UploadedFile && $documentFile instanceof LegalArchiveDocumentFile) {
            try {
                $this->documentFileService->addVersion(
                    $documentFile,
                    $file,
                    VersionInput::fromCreateData($userId, $data),
                    $this->createVersionAttempt($document, $attemptToken),
                );
                $this->heartbeatCreateAttempt($document, $attemptToken, 'retry_finalize');
            } catch (Throwable $exception) {
                if ($exception instanceof LegalDocumentVersionLeaseLost) {
                    throw new LegalDocumentCreateInProgress($document->refresh());
                }
                $this->markSourceCreateFailedWithoutMasking($document, $userId, $exception, $attemptToken, 'retry_upload');

                if (! $exception instanceof \App\Services\LegalArchive\Files\LegalDocumentScanFailed
                    && ! $exception instanceof \App\Services\LegalArchive\Files\LegalDocumentFileRejected
                ) {
                    throw new LegalDocumentCreateFailed($document->refresh(), $exception);
                }
                throw $exception;
            }
        }

        try {
            $this->markSourceCreateCompleted($document, $userId, $sourceCreateIdentity, $attemptToken);
        } catch (Throwable $exception) {
            $this->markSourceCreateFailedWithoutMasking($document, $userId, $exception, $attemptToken, 'retry_finalize');

            throw new LegalDocumentCreateFailed($document->refresh(), $exception);
        }

        return $this->findForOrganization($organizationId, (int) $document->id) ?? $document;
    }

    public function update(LegalArchiveDocument $document, int $organizationId, ?int $userId, array $data): LegalArchiveDocument
    {
        return DB::transaction(function () use ($document, $organizationId, $userId, $data): LegalArchiveDocument {
            $expectedLockVersion = (int) $data['lock_version'];
            $document = LegalArchiveDocument::query()
                ->where('organization_id', $organizationId)
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $document->lock_version !== $expectedLockVersion) {
                throw new LegalArchiveLockConflict((int) $document->lock_version);
            }
            if (array_key_exists('type_profile_code', $data)
                && (string) $data['type_profile_code'] !== (string) $document->type_profile_code
                && (string) $document->lifecycle_status !== 'draft') {
                throw new \DomainException('profile_correction_not_allowed');
            }
            $this->assertProjectBelongsToOrganization($organizationId, $data['primary_project_id'] ?? null);
            $this->sourceResolver->assertOwnedSource(
                $organizationId,
                $data['source_type'] ?? $document->source_type,
                $data['source_id'] ?? $document->source_id,
            );
            $before = $this->auditSnapshot($document);

            $payload = $this->documentPayload($organizationId, $userId, $data, true);
            unset($payload['lock_version']);
            $payload['lock_version'] = $expectedLockVersion + 1;
            $document->update($payload);

            if (array_key_exists('links', $data)) {
                $this->replaceLinks($document, $data['links'] ?? []);
            }

            $this->audit->recordForActorId('update', $document, $userId, [
                'before' => $before,
                'after' => $this->auditSnapshot($document->refresh()),
            ]);

            return $this->findForOrganization($organizationId, (int) $document->id) ?? $document;
        });
    }

    public function addVersion(
        LegalArchiveDocument $document,
        int $organizationId,
        ?int $userId,
        array $data,
        UploadedFile $file,
        bool $makeCurrent = true
    ): LegalArchiveDocumentVersion {
        if ((string) $document->source_create_status !== 'completed') {
            throw ValidationException::withMessages([
                'create_operation_id' => [trans_message('legal_archive.messages.create_recovery_required')],
            ]);
        }
        $documentFile = LegalArchiveDocumentFile::query()->firstOrCreate(
            [
                'document_id' => $document->id,
                'organization_id' => $organizationId,
                'role' => 'primary',
            ],
            [
                'title' => $document->title,
                'sort_order' => 0,
                'is_required' => true,
            ],
        );

        try {
            $version = $this->documentFileService->addVersion($documentFile, $file, new VersionInput(
                versionNumber: isset($data['version_number']) ? (string) $data['version_number'] : null,
                versionLabel: isset($data['version_label']) ? (string) $data['version_label'] : null,
                uploadedByUserId: $userId,
                metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
                makeCurrent: $makeCurrent,
                expectedDocumentLockVersion: isset($data['lock_version']) ? (int) $data['lock_version'] : null,
            ));

            return $version;
        } catch (LegalDocumentVersionPersistenceFailed $exception) {
            if ($documentFile->wasRecentlyCreated) {
                $documentFile->delete();
            }

            throw $exception;
        }
    }

    public function recoverCreate(
        User $actor,
        int $organizationId,
        string $operationId,
        ?UploadedFile $file,
    ): LegalArchiveDocument {
        if ($file instanceof UploadedFile) {
            $this->documentFileService->assertUploadAllowed($file);
        }
        $attemptToken = (string) Str::uuid();
        [$document, $documentFile, $retryAction, $versionInput] = DB::transaction(function () use (
            $actor,
            $organizationId,
            $operationId,
            $file,
            $attemptToken,
        ): array {
            $query = LegalArchiveDocument::query();
            $this->access->scopeAccessibleQuery($query, $actor, $organizationId);
            $document = $query
                ->where('created_by_user_id', $actor->id)
                ->where('create_operation_id', $operationId)
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $document->source_create_status === 'completed') {
                return [$document, null, null, null];
            }
            $documentFile = $document->files()->where('role', 'primary')->lockForUpdate()->first();
            $hasReadyVersion = $documentFile instanceof LegalArchiveDocumentFile
                && $documentFile->versions()->where('processing_status', 'ready')->exists();
            $plannedRetryAction = $this->assertCreateRecoveryInput($document, $hasReadyVersion, $file);
            $versionInput = null;
            if ($plannedRetryAction === 'retry_upload') {
                if (! $documentFile instanceof LegalArchiveDocumentFile) {
                    throw ValidationException::withMessages([
                        'file' => [trans_message('legal_archive.messages.create_recovery_input_unavailable')],
                    ]);
                }
                $versionInput = $this->documentFileService->lockVersionInputForRecovery(
                    $documentFile,
                    (string) $document->create_operation_id,
                );
                if (! $versionInput instanceof VersionInput) {
                    throw ValidationException::withMessages([
                        'file' => [trans_message('legal_archive.messages.create_recovery_input_unavailable')],
                    ]);
                }
            }
            $retryAction = $this->claimCreateAttempt($document, $attemptToken, $hasReadyVersion);
            $this->recordCreateRecoveryStarted(
                $document,
                (int) $actor->id,
                (string) $document->source_request_fingerprint,
                $retryAction,
            );

            return [$document, $documentFile, $retryAction, $versionInput];
        });

        if ($retryAction === null) {
            return $this->findForOrganization($organizationId, (int) $document->id) ?? $document;
        }
        if ($retryAction === 'retry_upload' && $file instanceof UploadedFile && $documentFile instanceof LegalArchiveDocumentFile) {
            if (! $versionInput instanceof VersionInput) {
                throw new InvalidArgumentException('legal_document_source_create_version_input_missing');
            }
            try {
                $this->documentFileService->addVersion(
                    $documentFile,
                    $file,
                    $versionInput,
                    $this->createVersionAttempt($document, $attemptToken),
                );
                $this->heartbeatCreateAttempt($document, $attemptToken, 'retry_finalize');
            } catch (Throwable $exception) {
                if ($exception instanceof LegalDocumentVersionLeaseLost) {
                    throw new LegalDocumentCreateInProgress($document->refresh());
                }
                $this->markSourceCreateFailedWithoutMasking(
                    $document,
                    (int) $actor->id,
                    $exception,
                    $attemptToken,
                    'retry_upload',
                );
                if (! $exception instanceof \App\Services\LegalArchive\Files\LegalDocumentScanFailed
                    && ! $exception instanceof \App\Services\LegalArchive\Files\LegalDocumentFileRejected
                ) {
                    throw new LegalDocumentCreateFailed($document->refresh(), $exception);
                }
                throw $exception;
            }
        }

        try {
            $this->markSourceCreateCompleted($document, (int) $actor->id, null, $attemptToken);
        } catch (Throwable $exception) {
            $this->markSourceCreateFailedWithoutMasking(
                $document,
                (int) $actor->id,
                $exception,
                $attemptToken,
                'retry_finalize',
            );
            throw new LegalDocumentCreateFailed($document->refresh(), $exception);
        }

        return $this->findForOrganization($organizationId, (int) $document->id) ?? $document;
    }

    public function currentVersionWithUrl(LegalArchiveDocument $document, User $actor): ?LegalArchiveDocumentVersion
    {
        $version = $document->currentVersion()->first();

        if (! $version instanceof LegalArchiveDocumentVersion) {
            return null;
        }

        $version->setAttribute(
            'download_url',
            $this->downloadService->temporaryUrl($version, $actor, 'download')
        );

        return $version;
    }

    private function baseQuery(User $actor, int $organizationId, array $filters): Builder
    {
        $query = LegalArchiveDocument::query();
        $this->access->scopeAccessibleQuery($query, $actor, $organizationId);
        $query->where('source_create_status', 'completed');

        $search = LegalArchiveSearchQuery::sanitize($filters['q'] ?? $filters['search'] ?? null);
        if ($search !== null) {
            $this->applySearch($query, $search);
        }

        foreach (['document_type', 'status', 'direction'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['project_id'])) {
            $query->where('primary_project_id', (int) $filters['project_id']);
        }

        if (! empty($filters['counterparty'])) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->where('counterparty_name', 'ILIKE', '%'.$filters['counterparty'].'%');
            } else {
                $query->whereRaw('LOWER(counterparty_name) LIKE ?', ['%'.mb_strtolower((string) $filters['counterparty']).'%']);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('document_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('document_date', '<=', $filters['date_to']);
        }

        if (array_key_exists('legal_hold', $filters) && $filters['legal_hold'] !== null && $filters['legal_hold'] !== '') {
            $query->where('legal_hold', filter_var($filters['legal_hold'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['retention_until_from'])) {
            $query->where('retention_until', '>=', $filters['retention_until_from']);
        }

        if (! empty($filters['retention_until_to'])) {
            $query->where('retention_until', '<=', $filters['retention_until_to']);
        }

        if (! empty($filters['link_type'])) {
            $query->whereHas('links', static function (Builder $linkQuery) use ($filters): void {
                $linkQuery->where('link_type', $filters['link_type']);
            });
        }

        return $query;
    }

    private function detailQuery(): Builder
    {
        return LegalArchiveDocument::query()->with([
            'currentVersion',
            'versions',
            'links',
            'project:id,name,status,organization_id',
            'createdBy:id,name,email',
            'files.currentVersion',
            'files.versions',
            'latestWorkflowInstance.steps',
            'signatureRequests',
            'signatures',
        ]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->whereRaw(LegalArchiveSearchQuery::postgresExpression(), [$search]);

            return;
        }

        $needle = mb_strtolower($search);

        $query->where(static function (Builder $nested) use ($needle): void {
            foreach (LegalArchiveSearchQuery::columns() as $column) {
                $nested->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.$needle.'%']);
            }
        });
    }

    private function documentPayload(int $organizationId, ?int $userId, array $data, bool $forUpdate = false): array
    {
        $allowed = [
            'primary_project_id',
            'title',
            'document_number',
            'document_type',
            'status',
            'type_profile_code',
            'confidentiality_level',
            'direction',
            'source_system',
            'counterparty_name',
            'document_date',
            'effective_from',
            'effective_until',
            'description',
            'legal_significance_status',
            'edo_status',
            'one_c_status',
            'source_type',
            'source_id',
            'source_idempotency_key',
            'metadata',
        ];

        $payload = Arr::only($data, $allowed);
        if (isset($data['type_profile_code'])) {
            $profile = $this->profiles->find($organizationId, (string) $data['type_profile_code']);
            $payload['type_profile_code'] = $profile->code;
            $payload['document_type'] = $profile->category;
            $payload['metadata'] = $this->profileValidator->validate($profile, (array) ($data['metadata'] ?? []));
            if (! array_key_exists('confidentiality_level', $data)) {
                $payload['confidentiality_level'] = $profile->confidentialityLevel;
            }
        }
        if ($forUpdate) {
            unset($payload['source_type'], $payload['source_id'], $payload['source_idempotency_key']);
        }
        $payload['updated_by_user_id'] = $userId;

        if (! $forUpdate) {
            $payload['organization_id'] = $organizationId;
            $payload['created_by_user_id'] = $userId;
            $payload['owner_user_id'] = $userId;
            $payload['status'] = $payload['status'] ?? 'draft';
            $payload['confidentiality_level'] = $payload['confidentiality_level'] ?? 'internal';
            $payload['direction'] = $payload['direction'] ?? 'internal';
            $payload['source_system'] = $payload['source_system'] ?? 'most';
            $payload['legal_significance_status'] = $payload['legal_significance_status'] ?? 'not_confirmed';
        }

        return $payload;
    }

    private function replaceLinks(LegalArchiveDocument $document, array $links): void
    {
        $document->links()->delete();

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $this->sourceResolver->assertOwnedSource(
                (int) $document->organization_id,
                $link['linked_type'] ?? null,
                $link['linked_id'] ?? null,
            );

            $document->links()->create([
                'organization_id' => $document->organization_id,
                'link_type' => $link['link_type'],
                'linked_type' => $link['linked_type'] ?? null,
                'linked_id' => $link['linked_id'] ?? null,
                'external_key' => $link['external_key'] ?? null,
                'display_name' => $link['display_name'],
                'url' => $link['url'] ?? null,
                'metadata' => $link['metadata'] ?? null,
            ]);
        }
    }

    private function assertProjectBelongsToOrganization(int $organizationId, mixed $projectId): void
    {
        if ($projectId === null || $projectId === '') {
            return;
        }

        $projectExists = Project::query()
            ->whereKey((int) $projectId)
            ->accessibleByOrganization($organizationId)
            ->exists();

        if (! $projectExists) {
            throw ValidationException::withMessages([
                'primary_project_id' => [trans_message('legal_archive.messages.project_not_available')],
            ]);
        }
    }

    private function sourceCreateIdentity(
        int $organizationId,
        ?int $userId,
        array $data,
    ): ?LegalDocumentSourceCreateIdentity {
        try {
            return LegalDocumentSourceCreateIdentity::fromInput($organizationId, $userId, $data);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'source_type' => [trans_message('legal_archive.messages.source_not_available')],
                'source_id' => [trans_message('legal_archive.messages.source_not_available')],
                'source_idempotency_key' => [trans_message('legal_archive.messages.source_not_available')],
            ]);
        }
    }

    private function resolveSourceCreateReplay(
        ?LegalDocumentSourceCreateIdentity $identity,
        int $organizationId,
        ?int $userId,
        ?string $operationKey,
        string $requestFingerprint,
        bool $lockForUpdate,
    ): ?LegalArchiveDocument {
        if ($identity === null) {
            if ($operationKey === null || $operationKey === '') {
                return null;
            }

            $manualQuery = LegalArchiveDocument::withTrashed()
                ->where('organization_id', $organizationId)
                ->where('created_by_user_id', $userId)
                ->whereNull('source_type')
                ->where('create_operation_key', $operationKey);
            if ($lockForUpdate) {
                $manualQuery->lockForUpdate();
            }
            $manual = $manualQuery->first();
            if (! $manual instanceof LegalArchiveDocument) {
                return null;
            }
            if (
                $manual->trashed()
                || ! is_string($manual->source_request_fingerprint)
                || ! hash_equals($manual->source_request_fingerprint, $requestFingerprint)
            ) {
                $this->sourceIdentityConflict();
            }

            return $this->assertReplayState($manual);
        }

        $sourceQuery = LegalArchiveDocument::withTrashed()
            ->where('organization_id', $identity->organizationId)
            ->where('source_type', $identity->sourceType)
            ->where('source_id', $identity->sourceId);
        $commandQuery = LegalArchiveDocument::withTrashed()
            ->where('organization_id', $identity->organizationId)
            ->where('source_idempotency_key', $identity->idempotencyKey);
        if ($identity->actorId === null) {
            $commandQuery->whereNull('created_by_user_id');
        } else {
            $commandQuery->where('created_by_user_id', $identity->actorId);
        }
        if ($lockForUpdate) {
            $sourceQuery->lockForUpdate();
            $commandQuery->lockForUpdate();
        }
        $sourceDocument = $sourceQuery->first();
        $commandDocument = $commandQuery->first();

        if (! $sourceDocument instanceof LegalArchiveDocument && ! $commandDocument instanceof LegalArchiveDocument) {
            return null;
        }

        if (
            ! $sourceDocument instanceof LegalArchiveDocument
            || ! $commandDocument instanceof LegalArchiveDocument
            || (int) $sourceDocument->id !== (int) $commandDocument->id
            || ! $identity->matches($sourceDocument)
            || ! is_string($sourceDocument->source_request_fingerprint)
            || ! hash_equals($sourceDocument->source_request_fingerprint, $requestFingerprint)
        ) {
            $this->sourceIdentityConflict();
        }

        return $this->assertReplayState($sourceDocument);
    }

    private function retryCreateAfterUniqueCollision(
        QueryException $exception,
        ?LegalDocumentSourceCreateIdentity $identity,
        int $organizationId,
        ?int $userId,
        array $data,
        ?UploadedFile $file,
        ?string $operationKey,
        string $requestFingerprint,
    ): LegalArchiveDocument {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            throw $exception;
        }

        $canonical = $this->resolveSourceCreateReplay(
            $identity,
            $organizationId,
            $userId,
            $operationKey,
            $requestFingerprint,
            false,
        );
        if (! $canonical instanceof LegalArchiveDocument) {
            throw $exception;
        }
        if ((string) $canonical->source_create_status === 'completed') {
            return $this->findForOrganization($organizationId, (int) $canonical->id) ?? $canonical;
        }

        return $this->create($organizationId, $userId, $data, $file);
    }

    private function assertCreateRecoveryInput(
        LegalArchiveDocument $document,
        bool $hasReadyVersion,
        ?UploadedFile $file,
    ): string {
        $decision = LegalDocumentCreateLeaseDecision::decide(
            (string) $document->source_create_status,
            $document->source_create_lease_expires_at?->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
            $hasReadyVersion,
            is_string($document->source_create_retry_action) ? $document->source_create_retry_action : null,
        );
        if ($decision->decision === 'in_progress') {
            throw new LegalDocumentCreateInProgress($document);
        }
        if ($decision->decision !== 'claim' || $decision->retryAction === null) {
            throw new InvalidArgumentException('legal_document_source_create_claim_invalid');
        }
        $retryAction = $decision->retryAction;
        if ($retryAction === 'retry_upload' && ! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => [trans_message('legal_archive.messages.file_required_for_recovery')],
            ]);
        }

        return $retryAction;
    }

    private function recordCreateRecoveryStarted(
        LegalArchiveDocument $document,
        ?int $userId,
        string $requestFingerprint,
        string $retryAction,
    ): void {
        $this->audit->recordForActorId('recovery_started', $document, $userId, [
            'source_event_id' => 'create-retry:'.$document->create_operation_id.':attempt-'.$document->source_create_attempt_count,
            'request_fingerprint' => $requestFingerprint,
            'retry_action' => $retryAction,
            'attempt_count' => (int) $document->source_create_attempt_count,
            'lease_expires_at' => $document->source_create_lease_expires_at?->toISOString(),
        ]);
    }

    private function assertReplayState(LegalArchiveDocument $document): LegalArchiveDocument
    {
        $primaryFile = $document->files()->where('role', 'primary')->first();
        $hasReadyVersion = $primaryFile instanceof LegalArchiveDocumentFile
            && $primaryFile->versions()->where('processing_status', 'ready')->exists();
        $decision = LegalDocumentCreateLeaseDecision::decide(
            (string) $document->source_create_status,
            $document->source_create_lease_expires_at?->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
            $hasReadyVersion,
            is_string($document->source_create_retry_action) ? $document->source_create_retry_action : null,
        );
        if ($decision->decision === 'in_progress') {
            throw new LegalDocumentCreateInProgress($document);
        }

        return $document;
    }

    private function newAttemptPayload(string $attemptToken, string $retryAction): array
    {
        $now = now();

        return [
            'source_create_status' => 'pending',
            'source_create_failure_fingerprint' => null,
            'source_create_failed_at' => null,
            'source_create_attempt_token' => $attemptToken,
            'source_create_attempt_count' => 1,
            'source_create_started_at' => $now,
            'source_create_heartbeat_at' => $now,
            'source_create_lease_expires_at' => $now->copy()->addSeconds(self::CREATE_LEASE_SECONDS),
            'source_create_retry_action' => $retryAction,
        ];
    }

    private function claimCreateAttempt(
        LegalArchiveDocument $document,
        string $attemptToken,
        bool $hasReadyVersion,
    ): string {
        $decision = LegalDocumentCreateLeaseDecision::decide(
            (string) $document->source_create_status,
            $document->source_create_lease_expires_at?->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
            $hasReadyVersion,
            is_string($document->source_create_retry_action) ? $document->source_create_retry_action : null,
        );
        if ($decision->decision === 'in_progress') {
            throw new LegalDocumentCreateInProgress($document);
        }
        if ($decision->decision !== 'claim' || $decision->retryAction === null) {
            throw new InvalidArgumentException('legal_document_source_create_claim_invalid');
        }
        $payload = $this->newAttemptPayload($attemptToken, $decision->retryAction);
        $payload['source_create_attempt_count'] = ((int) $document->source_create_attempt_count) + 1;
        $document->forceFill($payload)->save();

        return $decision->retryAction;
    }

    private function heartbeatCreateAttempt(
        LegalArchiveDocument $document,
        string $attemptToken,
        string $retryAction,
    ): void {
        $now = now();
        $updated = LegalArchiveDocument::query()
            ->whereKey($document->id)
            ->where('source_create_status', 'pending')
            ->where('source_create_attempt_token', $attemptToken)
            ->where('source_create_lease_expires_at', '>', $now)
            ->update([
                'source_create_heartbeat_at' => $now,
                'source_create_lease_expires_at' => $now->copy()->addSeconds(self::CREATE_LEASE_SECONDS),
                'source_create_retry_action' => $retryAction,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new InvalidArgumentException('legal_document_source_create_lease_lost');
        }
    }

    private function createVersionAttempt(
        LegalArchiveDocument $document,
        string $attemptToken,
    ): LegalDocumentVersionAttempt {
        $operationId = (string) $document->create_operation_id;

        return new LegalDocumentVersionAttempt(
            $operationId,
            $attemptToken,
            static function (LegalArchiveDocument $lockedDocument, string $token): void {
                if (
                    (string) $lockedDocument->source_create_status !== 'pending'
                    || ! LegalDocumentCreateLeaseDecision::ownsAttempt(
                        $lockedDocument->source_create_attempt_token,
                        $token,
                    )
                    || $lockedDocument->source_create_lease_expires_at === null
                    || ! $lockedDocument->source_create_lease_expires_at->isFuture()
                ) {
                    throw new LegalDocumentVersionLeaseLost;
                }
            },
            function (string $token) use ($document): void {
                $this->heartbeatCreateAttempt($document, $token, 'retry_upload');
            },
        );
    }

    private function primaryFile(LegalArchiveDocument $document, int $organizationId): LegalArchiveDocumentFile
    {
        return LegalArchiveDocumentFile::query()->firstOrCreate([
            'document_id' => $document->id,
            'organization_id' => $organizationId,
            'role' => 'primary',
        ], [
            'title' => $document->title,
            'sort_order' => 0,
            'is_required' => true,
        ]);
    }

    private function markSourceCreateCompleted(
        LegalArchiveDocument $document,
        ?int $userId,
        ?LegalDocumentSourceCreateIdentity $identity,
        string $attemptToken,
    ): void {
        DB::transaction(function () use ($document, $userId, $identity, $attemptToken): void {
            $locked = LegalArchiveDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ((string) $locked->source_create_status === 'completed') {
                return;
            }
            if (
                (string) $locked->source_create_status !== 'pending'
                || ! LegalDocumentCreateLeaseDecision::ownsAttempt($locked->source_create_attempt_token, $attemptToken)
            ) {
                throw new InvalidArgumentException('legal_document_source_create_transition_invalid');
            }
            $locked->forceFill([
                'source_create_status' => 'completed',
                'source_create_failure_fingerprint' => null,
                'source_create_failed_at' => null,
                'source_create_attempt_token' => null,
                'source_create_lease_expires_at' => null,
                'source_create_retry_action' => null,
            ])->save();
            $this->audit->recordForActorId('create_completed', $locked, $userId, [
                'after' => $this->auditSnapshot($locked),
                'source_event_id' => $identity === null
                    ? 'create:manual:completed'
                    : $identity->sourceEventId().':completed',
                'request_fingerprint' => $locked->source_request_fingerprint,
            ]);
        });
    }

    private function markSourceCreateFailedWithoutMasking(
        LegalArchiveDocument $document,
        ?int $userId,
        Throwable $original,
        string $attemptToken,
        string $retryAction,
    ): void {
        $failureFingerprint = hash('sha256', $original::class.'|'.$original->getMessage());
        try {
            DB::transaction(function () use ($document, $userId, $failureFingerprint, $attemptToken, $retryAction): void {
                $locked = LegalArchiveDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                if (
                    (string) $locked->source_create_status !== 'pending'
                    || ! LegalDocumentCreateLeaseDecision::ownsAttempt($locked->source_create_attempt_token, $attemptToken)
                ) {
                    return;
                }
                $locked->forceFill([
                    'source_create_status' => 'failed',
                    'source_create_failure_fingerprint' => $failureFingerprint,
                    'source_create_failed_at' => now(),
                    'source_create_attempt_token' => null,
                    'source_create_lease_expires_at' => null,
                    'source_create_retry_action' => $retryAction,
                ])->save();
                $this->audit->recordForActorId('create_failed', $locked, $userId, [
                    'source_event_id' => 'create-failed:'.$failureFingerprint,
                    'request_fingerprint' => $locked->source_request_fingerprint,
                    'failure_fingerprint' => $failureFingerprint,
                ]);
            });
        } catch (Throwable $auditFailure) {
            Log::error('legal_archive.source_create_failure_audit_error', [
                'document_id' => (int) $document->id,
                'organization_id' => (int) $document->organization_id,
                'failure_fingerprint' => $failureFingerprint,
                'audit_failure' => $auditFailure::class,
            ]);
            try {
                LegalArchiveDocument::query()
                    ->whereKey($document->id)
                    ->where('source_create_status', 'pending')
                    ->where('source_create_attempt_token', $attemptToken)
                    ->update([
                        'source_create_status' => 'failed',
                        'source_create_failure_fingerprint' => $failureFingerprint,
                        'source_create_failed_at' => now(),
                        'source_create_attempt_token' => null,
                        'source_create_lease_expires_at' => null,
                        'source_create_retry_action' => $retryAction,
                    ]);
            } catch (Throwable $stateFailure) {
                Log::critical('legal_archive.source_create_failure_state_error', [
                    'document_id' => (int) $document->id,
                    'failure_fingerprint' => $failureFingerprint,
                    'state_failure' => $stateFailure::class,
                ]);
            }
        }
    }

    private function sourceIdentityConflict(): never
    {
        throw ValidationException::withMessages([
            'source_id' => [trans_message('legal_archive.messages.source_already_linked')],
        ]);
    }

    private function auditSnapshot(LegalArchiveDocument $document): array
    {
        return Arr::only($document->getAttributes(), [
            'id',
            'organization_id',
            'primary_project_id',
            'title',
            'document_number',
            'document_type',
            'status',
            'lifecycle_status',
            'approval_status',
            'signature_status',
            'current_primary_version_id',
            'lock_version',
            'archived_at',
            'source_create_status',
            'source_request_fingerprint',
        ]);
    }
}
