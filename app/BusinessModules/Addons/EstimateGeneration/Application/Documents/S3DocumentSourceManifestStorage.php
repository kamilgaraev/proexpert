<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\Services\Storage\FileService;

final readonly class S3DocumentSourceManifestStorage implements DocumentSourceManifestStorage
{
    public function __construct(private FileService $files) {}

    public function read(EstimateGenerationDocument $document): string
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

        $stream = $this->files->disk($organization)->readStream((string) $document->storage_path);

        if (! is_resource($stream)) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }

        $content = '';
        try {
            while (! feof($stream)) {
                $remaining = $maxBytes + 1 - strlen($content);
                if ($remaining <= 0) {
                    throw new TypedFailureException(FailureCategory::UserActionRequired, 'document_source_too_large', [
                        'max_file_size_bytes' => $maxBytes,
                    ]);
                }
                $chunk = fread($stream, min(1_048_576, $remaining));
                if (! is_string($chunk)) {
                    throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
                }
                if ($chunk === '' && ! feof($stream)) {
                    throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
                }
                $content .= $chunk;
            }
        } finally {
            fclose($stream);
        }

        if ($content === '' || strlen($content) !== $declaredBytes) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }

        return $content;
    }

    public function put(
        EstimateGenerationDocument $document,
        string $sourceVersion,
        DocumentUnitType $type,
        int $index,
        string $content,
        string $contentType = 'text/plain',
    ): string {
        $organization = $document->session?->organization;

        if ($organization === null) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_organization_unavailable');
        }

        $path = $this->files->putContent(
            $content,
            sprintf('estimate-generation/sessions/%d/documents/%d/manifests/%s', $document->session_id, $document->id, str_replace(':', '-', $sourceVersion)),
            sprintf('%s-%05d.%s', $type->value, $index, match ($contentType) {
                'application/json' => 'json',
                'image/png' => 'png',
                'text/plain' => 'txt',
                default => throw new TypedFailureException(FailureCategory::Terminal, 'document_artifact_content_type_invalid'),
            }),
            'private',
            $organization,
        );

        if (! is_string($path) || ! str_starts_with($path, 'org-'.$organization->id.'/')) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_artifact_write_failed');
        }

        return $path;
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
