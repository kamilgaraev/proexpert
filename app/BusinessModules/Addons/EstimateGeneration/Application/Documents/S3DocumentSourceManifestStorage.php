<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectTransportException;
use App\Services\Storage\FileService;

final readonly class S3DocumentSourceManifestStorage implements DocumentSourceManifestStorage
{
    public function __construct(
        private FileService $files,
        private BoundedVersionedS3ObjectReader $reader,
    ) {}

    public function open(EstimateGenerationDocument $document, string $sourceVersion): SeekableDocumentSource
    {
        $document->loadMissing('session.organization');
        $organization = $document->session?->organization;

        if ($organization === null || ! str_starts_with((string) $document->storage_path, 'org-'.$organization->id.'/')) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_scope_invalid');
        }

        $maxBytes = $this->maxReadableBytes($document);
        $declaredBytes = (int) ($document->file_size_bytes ?? 0);
        if ($declaredBytes < 1 || $declaredBytes > $maxBytes) {
            throw new TypedFailureException(FailureCategory::UserActionRequired, 'document_source_too_large', [
                'file_size_bytes' => $declaredBytes,
                'max_file_size_bytes' => $maxBytes,
            ]);
        }

        $temporary = tmpfile();
        if (! is_resource($temporary)) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }

        try {
            $versionId = is_array($document->meta) ? $document->meta['storage_version_id'] ?? null : null;
            if (! is_string($versionId) || trim($versionId) === '') {
                throw new TypedFailureException(FailureCategory::Terminal, 'document_source_provenance_required');
            }
            $source = $this->reader->read(
                (int) $organization->getKey(),
                (string) $document->storage_path,
                $maxBytes,
                $declaredBytes,
                $sourceVersion,
                $versionId,
            );
            $offset = 0;
            while ($offset < $source->bytes) {
                $written = fwrite($temporary, substr($source->body, $offset));
                if (! is_int($written) || $written < 1) {
                    throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
                }
                $offset += $written;
            }
            if (! fflush($temporary) || ! rewind($temporary)) {
                throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
            }
        } catch (S3ObjectLocatorException $exception) {
            fclose($temporary);
            throw new TypedFailureException(FailureCategory::Terminal, 'document_source_integrity_failed', previous: $exception);
        } catch (S3ObjectTransportException $exception) {
            fclose($temporary);
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable', previous: $exception);
        } catch (\Throwable $exception) {
            fclose($temporary);
            throw $exception;
        }

        return new SeekableDocumentSource($temporary, $declaredBytes);
    }

    public function put(
        EstimateGenerationDocument $document,
        string $sourceVersion,
        DocumentUnitType $type,
        int $index,
        string $content,
        string $contentType = 'text/plain',
    ): StoredDocumentArtifact {
        $organization = $document->session?->organization;

        if ($organization === null) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_organization_unavailable');
        }

        $directory = sprintf('estimate-generation/sessions/%d/documents/%d/manifests/%s', $document->session_id, $document->id, str_replace(':', '-', $sourceVersion));
        $filename = sprintf('%s-%05d.%s', $type->value, $index, match ($contentType) {
            'application/json' => 'json',
            'image/png' => 'png',
            'text/plain' => 'txt',
            default => throw new TypedFailureException(FailureCategory::Terminal, 'document_artifact_content_type_invalid'),
        });
        $path = sprintf('org-%d/%s/%s', $organization->id, $directory, $filename);
        $stored = $this->files->putImmutable($path, $content, $contentType);

        if (! hash_equals($path, $stored['path']) || ! hash_equals(hash('sha256', $content), $stored['sha256'])
            || $stored['size'] !== strlen($content) || ! hash_equals($content, $stored['body'])) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_artifact_write_failed');
        }

        return new StoredDocumentArtifact(
            $path,
            $stored['size'],
            'sha256:'.$stored['sha256'],
            (string) $stored['version_id'],
            $contentType,
        );
    }

    private function maxReadableBytes(EstimateGenerationDocument $document): int
    {
        $mimeType = strtolower((string) $document->mime_type);

        return match (true) {
            $mimeType === 'application/pdf' => max(1, (int) config('estimate-generation.ocr.max_pdf_file_bytes', 200 * 1024 * 1024)),
            str_contains($mimeType, 'spreadsheet'),
            str_contains($mimeType, 'excel'),
            str_contains($mimeType, 'csv') => max(1, (int) config('estimate-generation.ocr.max_spreadsheet_file_bytes', 50 * 1024 * 1024)),
            in_array($mimeType, ['application/dxf', 'application/dwg', 'image/vnd.dwg'], true) => max(1, (int) config('estimate-generation.ocr.max_cad_file_bytes', 200 * 1024 * 1024)),
            default => max(1, (int) config('estimate-generation.ocr.max_sync_file_bytes', 10 * 1024 * 1024)),
        };
    }
}
