<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentLegalArchiveVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ExecutiveDocumentLegalArchiveService
{
    public function __construct(
        private readonly ExecutiveDocumentMutationGuard $mutationGuard,
        private readonly FileService $files,
        private readonly LegalArchiveRegistryService $registry,
        private readonly AuthorizationService $authorization,
    ) {}

    public function link(int $versionId, int $actorId): LegalArchiveDocumentVersion
    {
        $state = DB::transaction(fn (): array => $this->prepare($versionId, $actorId));
        if ($state['mapping'] instanceof ExecutiveDocumentLegalArchiveVersion) {
            return $state['archive_version'];
        }

        $temporary = null;
        try {
            $temporary = $this->materializeSource($state['source']);
            $archiveVersion = $this->publish($state, $actorId, $temporary);
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }

        return DB::transaction(
            fn (): LegalArchiveDocumentVersion => $this->persistMapping($versionId, $actorId, $archiveVersion),
        );
    }

    private function prepare(int $versionId, int $actorId): array
    {
        $sourceProbe = ExecutiveDocumentVersion::query()->findOrFail($versionId);
        $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($this->documentSetId($sourceProbe));
        $document = ExecutiveDocument::query()->lockForUpdate()->findOrFail($sourceProbe->document_id);
        $source = ExecutiveDocumentVersion::query()->lockForUpdate()->findOrFail($versionId);
        $this->assertActorAndOwnership($source, $document, $set, $actorId);
        $mapping = ExecutiveDocumentLegalArchiveVersion::query()
            ->where('organization_id', $document->organization_id)
            ->where('executive_document_version_id', $source->id)
            ->lockForUpdate()
            ->first();

        return [
            'mapping' => $mapping,
            'archive_version' => $mapping instanceof ExecutiveDocumentLegalArchiveVersion
                ? $this->archiveVersionForMapping($mapping, $source, $document)
                : null,
            'source' => $source,
            'document' => $document,
        ];
    }

    private function publish(array $state, int $actorId, string $temporary): LegalArchiveDocumentVersion
    {
        $source = $state['source'];
        $document = $state['document'];
        $archive = LegalArchiveDocument::query()
            ->where('organization_id', $document->organization_id)
            ->where('source_type', 'executive_document')
            ->where('source_id', (string) $document->id)
            ->first();
        if ($archive instanceof LegalArchiveDocument
            && (int) $archive->primary_project_id !== (int) $document->project_id) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
        $metadata = [
            'executive_document_version_id' => (int) $source->id,
            'executive_document_content_hash' => (string) $source->content_hash,
        ];
        $payload = [
            'primary_project_id' => (int) $document->project_id,
            'title' => (string) $document->title,
            'document_type' => 'executive_document',
            'source_type' => 'executive_document',
            'source_id' => (string) $document->id,
            'source_idempotency_key' => "executive-document-{$document->id}",
            'create_operation_key' => "executive-document-legal-archive-{$document->id}",
            'metadata' => ['executive_document_id' => (int) $document->id],
        ];
        if (! $archive instanceof LegalArchiveDocument || (string) $archive->source_create_status !== 'completed') {
            $archive = $this->registry->create((int) $document->organization_id, $actorId, $payload);
        }
        $archiveVersion = $this->findArchiveVersion($archive, $source, $document);
        if (! $archiveVersion instanceof LegalArchiveDocumentVersion) {
            $archiveVersion = $this->registry->addVersion(
                $archive,
                (int) $document->organization_id,
                $actorId,
                [
                    'version_number' => (string) $source->version_number,
                    'version_label' => (string) $source->version_number,
                    'metadata' => $metadata,
                ],
                $this->uploadedFile($temporary, $source),
            );
        }

        $this->assertReadyArchiveVersion($archiveVersion, $source, $document);

        return $archiveVersion;
    }

    private function persistMapping(int $versionId, int $actorId, LegalArchiveDocumentVersion $archiveVersion): LegalArchiveDocumentVersion
    {
        $sourceProbe = ExecutiveDocumentVersion::query()->findOrFail($versionId);
        $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($this->documentSetId($sourceProbe));
        $document = ExecutiveDocument::query()->lockForUpdate()->findOrFail($sourceProbe->document_id);
        $source = ExecutiveDocumentVersion::query()->lockForUpdate()->findOrFail($versionId);
        $this->assertActorAndOwnership($source, $document, $set, $actorId);
        $existing = ExecutiveDocumentLegalArchiveVersion::query()
            ->where('organization_id', $document->organization_id)
            ->where('executive_document_version_id', $source->id)
            ->lockForUpdate()
            ->first();
        if ($existing instanceof ExecutiveDocumentLegalArchiveVersion) {
            return $this->archiveVersionForMapping($existing, $source, $document);
        }
        $this->assertReadyArchiveVersion($archiveVersion, $source, $document);

        return ExecutiveDocumentLegalArchiveVersion::query()->create([
            'organization_id' => (int) $document->organization_id,
            'executive_document_version_id' => (int) $source->id,
            'legal_archive_document_version_id' => (int) $archiveVersion->id,
            'source_content_hash' => (string) $source->content_hash,
        ])->legalArchiveVersion()->firstOrFail();
    }

    private function documentSetId(ExecutiveDocumentVersion $version): int
    {
        return (int) ExecutiveDocument::query()->whereKey($version->document_id)->value('document_set_id');
    }

    private function assertSourceOwnership(
        ExecutiveDocumentVersion $source,
        ExecutiveDocument $document,
        ExecutiveDocumentSet $set,
    ): void {
        if ((int) $source->organization_id !== (int) $document->organization_id
            || (int) $document->organization_id !== (int) $set->organization_id
            || (int) $document->project_id !== (int) $set->project_id
            || (string) $source->content_hash === '') {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
    }

    private function assertActorAndOwnership(
        ExecutiveDocumentVersion $source,
        ExecutiveDocument $document,
        ExecutiveDocumentSet $set,
        int $actorId,
    ): void {
        $this->assertSourceOwnership($source, $document, $set);
        $this->mutationGuard->assertActor($document, $actorId, 'executive-documentation.edit');
        $actor = User::query()->findOrFail($actorId);
        if (! $this->authorization->can($actor, 'legal_archive.create', [
            'organization_id' => (int) $document->organization_id,
            'project_id' => (int) $document->project_id,
        ])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }
    }

    private function archiveVersionForMapping(
        ExecutiveDocumentLegalArchiveVersion $mapping,
        ExecutiveDocumentVersion $source,
        ExecutiveDocument $document,
    ): LegalArchiveDocumentVersion {
        if (! hash_equals((string) $source->content_hash, (string) $mapping->source_content_hash)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.version_conflict'), 409);
        }

        $version = LegalArchiveDocumentVersion::query()
            ->where('organization_id', $mapping->organization_id)
            ->whereJsonContains('metadata', ['executive_document_version_id' => (int) $source->id])
            ->where('content_hash', (string) $source->content_hash)
            ->whereHas('document', function ($query) use ($document): void {
                $query->where('organization_id', $document->organization_id)
                    ->where('source_type', 'executive_document')
                    ->where('source_id', (string) $document->id)
                    ->where('primary_project_id', (int) $document->project_id);
            })
            ->findOrFail($mapping->legal_archive_document_version_id);
        $this->assertReadyArchiveVersion($version, $source, $document);

        return $version;
    }

    private function findArchiveVersion(
        LegalArchiveDocument $archive,
        ExecutiveDocumentVersion $source,
        ExecutiveDocument $document,
    ): ?LegalArchiveDocumentVersion {
        $version = $archive->versions()
            ->where('organization_id', $archive->organization_id)
            ->whereJsonContains('metadata', ['executive_document_version_id' => (int) $source->id])
            ->where('content_hash', (string) $source->content_hash)
            ->orderByDesc('id')
            ->first();
        if ($version instanceof LegalArchiveDocumentVersion) {
            $this->assertReadyArchiveVersion($version, $source, $document);
        }

        return $version;
    }

    private function assertReadyArchiveVersion(
        LegalArchiveDocumentVersion $version,
        ExecutiveDocumentVersion $source,
        ExecutiveDocument $document,
    ): void {
        if ((string) $version->processing_status !== 'ready'
            || ! hash_equals((string) $source->content_hash, (string) $version->content_hash)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.version_conflict'), 409);
        }
        $metadata = (array) $version->metadata;
        if ((int) ($metadata['executive_document_version_id'] ?? 0) !== (int) $source->id) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.version_conflict'), 409);
        }
        $owner = $version->document;
        if (! $owner instanceof LegalArchiveDocument
            || (int) $owner->organization_id !== (int) $document->organization_id
            || (string) $owner->source_type !== 'executive_document'
            || (string) $owner->source_id !== (string) $document->id
            || (int) $owner->primary_project_id !== (int) $document->project_id) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.version_conflict'), 409);
        }
    }

    private function materializeSource(ExecutiveDocumentVersion $source): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'most-exec-la-');
        if ($temporary === false) {
            throw new RuntimeException('executive_document_archive_temporary_file');
        }
        if (! str_starts_with((string) $source->file_url, 'org-'.(int) $source->organization_id.'/')) {
            unlink($temporary);
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }

        try {
            $stream = $this->files->readCurrent((string) $source->file_url);
            try {
                $target = fopen($temporary, 'wb');
                if ($target === false) {
                    throw new RuntimeException('executive_document_archive_temporary_file');
                }
                $written = 0;
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('executive_document_archive_source_read_failed');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $written += strlen($chunk);
                    if ($written > 25 * 1024 * 1024) {
                        throw new RuntimeException('executive_document_archive_source_too_large');
                    }
                    if (fwrite($target, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('executive_document_archive_temporary_file');
                    }
                }
            } finally {
                fclose($stream);
                if (isset($target) && is_resource($target)) {
                    fclose($target);
                }
            }
            if (! hash_equals((string) $source->content_hash, (string) hash_file('sha256', $temporary))) {
                throw new BusinessLogicException(trans_message('executive_documentation.errors.version_conflict'), 409);
            }
        } catch (\Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        }

        return $temporary;
    }

    private function filename(ExecutiveDocumentVersion $source): string
    {
        $extension = strtolower((string) pathinfo((string) $source->file_url, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new RuntimeException('executive_document_archive_source_extension_missing');
        }

        return 'executive-document-version-'.$source->id.'.'.$extension;
    }

    private function uploadedFile(string $temporary, ExecutiveDocumentVersion $source): UploadedFile
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if (! is_string($mime) || $mime === '') {
            throw new RuntimeException('executive_document_archive_source_mime_missing');
        }

        return new UploadedFile($temporary, $this->filename($source), $mime, null, true);
    }
}
