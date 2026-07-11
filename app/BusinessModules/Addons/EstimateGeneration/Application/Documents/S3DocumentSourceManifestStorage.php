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

        $stream = $this->files->disk($organization)->readStream((string) $document->storage_path);

        if (! is_resource($stream)) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }

        try {
            $content = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if (! is_string($content) || $content === '') {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }

        return $content;
    }

    public function put(EstimateGenerationDocument $document, string $sourceVersion, DocumentUnitType $type, int $index, string $content): string
    {
        $organization = $document->session?->organization;

        if ($organization === null) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_organization_unavailable');
        }

        $path = $this->files->putContent(
            $content,
            sprintf('estimate-generation/sessions/%d/documents/%d/manifests/%s', $document->session_id, $document->id, str_replace(':', '-', $sourceVersion)),
            sprintf('%s-%05d.txt', $type->value, $index),
            'private',
            $organization,
        );

        if (! is_string($path) || ! str_starts_with($path, 'org-'.$organization->id.'/')) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_artifact_write_failed');
        }

        return $path;
    }
}
