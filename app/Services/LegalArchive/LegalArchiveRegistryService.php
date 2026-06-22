<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Storage\FileService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

use function trans_message;

final class LegalArchiveRegistryService
{
    public function __construct(
        private readonly FileService $fileService,
    ) {
    }

    public function paginate(int $organizationId, array $filters): LengthAwarePaginator
    {
        $perPage = max(10, min((int) ($filters['per_page'] ?? 20), 100));

        return $this->baseQuery($organizationId, $filters)
            ->with(['currentVersion', 'links', 'project:id,name,status,organization_id'])
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function summary(int $organizationId, array $filters): array
    {
        $query = $this->baseQuery($organizationId, $filters);

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
            ->forOrganization($organizationId)
            ->with([
                'currentVersion',
                'versions',
                'links',
                'project:id,name,status,organization_id',
                'createdBy:id,name,email',
            ])
            ->find($documentId);
    }

    public function create(int $organizationId, ?int $userId, array $data, ?UploadedFile $file = null): LegalArchiveDocument
    {
        return DB::transaction(function () use ($organizationId, $userId, $data, $file): LegalArchiveDocument {
            $this->assertProjectBelongsToOrganization($organizationId, $data['primary_project_id'] ?? null);

            $document = LegalArchiveDocument::query()->create($this->documentPayload($organizationId, $userId, $data));
            $this->replaceLinks($document, $data['links'] ?? []);

            if ($file instanceof UploadedFile) {
                $this->addVersion($document, $organizationId, $userId, [
                    'version_number' => $data['version_number'] ?? '1.0',
                    'version_label' => $data['version_label'] ?? null,
                    'status' => $data['version_status'] ?? 'uploaded',
                    'metadata' => $data['version_metadata'] ?? null,
                ], $file);
            }

            return $this->findForOrganization($organizationId, (int) $document->id) ?? $document;
        });
    }

    public function update(LegalArchiveDocument $document, int $organizationId, ?int $userId, array $data): LegalArchiveDocument
    {
        return DB::transaction(function () use ($document, $organizationId, $userId, $data): LegalArchiveDocument {
            $this->assertProjectBelongsToOrganization($organizationId, $data['primary_project_id'] ?? null);

            $document->update($this->documentPayload($organizationId, $userId, $data, true));

            if (array_key_exists('links', $data)) {
                $this->replaceLinks($document, $data['links'] ?? []);
            }

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
        $organization = Organization::query()->find($organizationId);

        if (! $organization instanceof Organization) {
            throw ValidationException::withMessages([
                'organization' => [trans_message('legal_archive.messages.organization_not_found')],
            ]);
        }

        $path = $this->fileService->upload(
            $file,
            "legal-archive/documents/{$document->id}/versions",
            null,
            'private',
            $organization
        );

        if ($path === false) {
            throw new RuntimeException(trans_message('legal_archive.messages.file_upload_error'));
        }

        if ($makeCurrent) {
            LegalArchiveDocumentVersion::query()
                ->where('document_id', $document->id)
                ->update(['is_current' => false]);
        }

        $metadata = $data['metadata'] ?? null;

        return LegalArchiveDocumentVersion::query()->create([
            'document_id' => $document->id,
            'organization_id' => $organizationId,
            'version_number' => (string) ($data['version_number'] ?? $this->nextVersionNumber($document)),
            'version_label' => $data['version_label'] ?? null,
            'is_current' => $makeCurrent,
            'status' => $data['status'] ?? 'uploaded',
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => (int) ($file->getSize() ?: 0),
            'content_hash' => $this->contentHash($file),
            'metadata_hash' => $metadata === null ? null : hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)),
            'uploaded_by_user_id' => $userId,
            'uploaded_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    public function currentVersionWithUrl(LegalArchiveDocument $document, int $organizationId): ?LegalArchiveDocumentVersion
    {
        $version = $document->currentVersion()->first();

        if (! $version instanceof LegalArchiveDocumentVersion) {
            return null;
        }

        $version->setAttribute(
            'download_url',
            $this->fileService->temporaryUrl($version->file_path, 10, $document->organization)
        );

        return $version;
    }

    private function baseQuery(int $organizationId, array $filters): Builder
    {
        $query = LegalArchiveDocument::query()->forOrganization($organizationId);

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
                $query->where('counterparty_name', 'ILIKE', '%' . $filters['counterparty'] . '%');
            } else {
                $query->whereRaw('LOWER(counterparty_name) LIKE ?', ['%' . mb_strtolower((string) $filters['counterparty']) . '%']);
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

    private function applySearch(Builder $query, string $search): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->whereRaw(LegalArchiveSearchQuery::postgresExpression(), [$search]);

            return;
        }

        $needle = mb_strtolower($search);

        $query->where(static function (Builder $nested) use ($needle): void {
            foreach (LegalArchiveSearchQuery::columns() as $column) {
                $nested->orWhereRaw("LOWER({$column}) LIKE ?", ['%' . $needle . '%']);
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
            'retention_policy',
            'retention_basis',
            'retention_started_at',
            'retention_until',
            'legal_hold',
            'metadata',
        ];

        $payload = Arr::only($data, $allowed);
        $payload['updated_by_user_id'] = $userId;

        if (! $forUpdate) {
            $payload['organization_id'] = $organizationId;
            $payload['created_by_user_id'] = $userId;
            $payload['status'] = $payload['status'] ?? 'draft';
            $payload['direction'] = $payload['direction'] ?? 'internal';
            $payload['source_system'] = $payload['source_system'] ?? 'prohelper';
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

    private function nextVersionNumber(LegalArchiveDocument $document): string
    {
        $count = LegalArchiveDocumentVersion::query()->where('document_id', $document->id)->count();

        return (string) ($count + 1);
    }

    private function contentHash(UploadedFile $file): ?string
    {
        $realPath = $file->getRealPath();

        return $realPath === false ? null : hash_file('sha256', $realPath);
    }
}
