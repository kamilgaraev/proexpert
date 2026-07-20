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
use App\Services\LegalArchive\Files\LegalDocumentVersionPersistenceFailed;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\LegalArchive\Sources\LegalDocumentSourceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class LegalArchiveRegistryService
{
    public function __construct(
        private readonly LegalDocumentFileService $documentFileService,
        private readonly LegalDocumentDownloadService $downloadService,
        private readonly LegalDocumentAudit $audit,
        private readonly LegalDocumentSourceResolver $sourceResolver,
        private readonly LegalDocumentAccessService $access,
    ) {}

    public function paginate(User $actor, int $organizationId, array $filters): LengthAwarePaginator
    {
        $perPage = max(10, min((int) ($filters['per_page'] ?? 20), 100));

        return $this->baseQuery($actor, $organizationId, $filters)
            ->with(['currentVersion', 'links', 'project:id,name,status,organization_id'])
            ->orderByDesc('document_date')
            ->orderByDesc('id')
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
        return $this->detailQuery()
            ->forOrganization($organizationId)
            ->find($documentId);
    }

    public function findForAuthorization(int $documentId): ?LegalArchiveDocument
    {
        return $this->detailQuery()->find($documentId);
    }

    public function create(int $organizationId, ?int $userId, array $data, ?UploadedFile $file = null): LegalArchiveDocument
    {
        try {
            [$document, $documentFile] = DB::transaction(function () use ($organizationId, $userId, $data, $file): array {
                $this->assertProjectBelongsToOrganization($organizationId, $data['primary_project_id'] ?? null);
                $this->sourceResolver->assertOwnedSource($organizationId, $data['source_type'] ?? null, $data['source_id'] ?? null);
                $this->assertSourceIdentityAvailable($organizationId, $data['source_type'] ?? null, $data['source_id'] ?? null);

                $document = LegalArchiveDocument::query()->create($this->documentPayload($organizationId, $userId, $data));
                $this->replaceLinks($document, $data['links'] ?? []);
                $documentFile = $file instanceof UploadedFile
                    ? LegalArchiveDocumentFile::query()->create([
                        'document_id' => $document->id,
                        'organization_id' => $organizationId,
                        'role' => 'primary',
                        'title' => $document->title,
                        'sort_order' => 0,
                        'is_required' => true,
                    ])
                    : null;
                $this->audit->recordForActorId('create', $document, $userId, [
                    'after' => $this->auditSnapshot($document),
                    'source_event_id' => $this->sourceEventId($data, 'create', (string) $document->id),
                ]);

                return [$document, $documentFile];
            });
        } catch (QueryException $exception) {
            if ($this->sourceIdentityExists($organizationId, $data['source_type'] ?? null, $data['source_id'] ?? null)) {
                $this->sourceIdentityConflict();
            }

            throw $exception;
        }

        if ($file instanceof UploadedFile && $documentFile instanceof LegalArchiveDocumentFile) {
            try {
                $this->documentFileService->addVersion($documentFile, $file, new VersionInput(
                    versionNumber: isset($data['version_number']) ? (string) $data['version_number'] : '1.0',
                    versionLabel: isset($data['version_label']) ? (string) $data['version_label'] : null,
                    uploadedByUserId: $userId,
                    metadata: is_array($data['version_metadata'] ?? null) ? $data['version_metadata'] : null,
                ));
            } catch (LegalDocumentVersionPersistenceFailed $exception) {
                DB::transaction(static fn () => $document->forceDelete());

                throw $exception;
            }
        }

        return $this->findForOrganization($organizationId, (int) $document->id) ?? $document;
    }

    public function update(LegalArchiveDocument $document, int $organizationId, ?int $userId, array $data): LegalArchiveDocument
    {
        return DB::transaction(function () use ($document, $organizationId, $userId, $data): LegalArchiveDocument {
            $this->assertProjectBelongsToOrganization($organizationId, $data['primary_project_id'] ?? null);
            $this->sourceResolver->assertOwnedSource(
                $organizationId,
                $data['source_type'] ?? $document->source_type,
                $data['source_id'] ?? $document->source_id,
            );
            $before = $this->auditSnapshot($document);

            $document->update($this->documentPayload($organizationId, $userId, $data, true));

            if (array_key_exists('links', $data)) {
                $this->replaceLinks($document, $data['links'] ?? []);
            }

            $this->audit->recordForActorId('update', $document, $userId, [
                'before' => $before,
                'after' => $this->auditSnapshot($document->refresh()),
                'source_event_id' => $this->sourceEventId($data, 'update', (string) $document->id),
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
            return $this->documentFileService->addVersion($documentFile, $file, new VersionInput(
                versionNumber: isset($data['version_number']) ? (string) $data['version_number'] : null,
                versionLabel: isset($data['version_label']) ? (string) $data['version_label'] : null,
                uploadedByUserId: $userId,
                metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : null,
                makeCurrent: $makeCurrent,
            ));
        } catch (LegalDocumentVersionPersistenceFailed $exception) {
            if ($documentFile->wasRecentlyCreated) {
                $documentFile->delete();
            }

            throw $exception;
        }
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
        $query = LegalArchiveDocument::query()->forOrganization($organizationId);
        $this->access->scopeInternalQuery($query, $actor, $organizationId);

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
            'parties',
            'project:id,name,status,organization_id',
            'createdBy:id,name,email',
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
        if ($forUpdate) {
            unset($payload['source_type'], $payload['source_id'], $payload['source_idempotency_key']);
        }
        $payload['updated_by_user_id'] = $userId;

        if (! $forUpdate) {
            $payload['organization_id'] = $organizationId;
            $payload['created_by_user_id'] = $userId;
            $payload['status'] = $payload['status'] ?? 'draft';
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

    private function assertSourceIdentityAvailable(int $organizationId, mixed $sourceType, mixed $sourceId): void
    {
        if ($this->sourceIdentityExists($organizationId, $sourceType, $sourceId)) {
            $this->sourceIdentityConflict();
        }
    }

    private function sourceIdentityExists(int $organizationId, mixed $sourceType, mixed $sourceId): bool
    {
        if (! is_string($sourceType) || $sourceType === '' || $sourceId === null || $sourceId === '') {
            return false;
        }

        return LegalArchiveDocument::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('source_type', $sourceType)
            ->where('source_id', (string) $sourceId)
            ->exists();
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
        ]);
    }

    private function sourceEventId(array $data, string $action, string $documentId): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' ? "{$action}:{$documentId}:{$key}" : null;
    }
}
