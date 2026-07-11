<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\Services\Storage\FileService;

final readonly class S3DocumentUnitContentReader implements DocumentUnitContentReader
{
    public function __construct(private FileService $files) {}

    public function open(DocumentUnitExecutionContext $context)
    {
        $path = is_string($context->locator['artifact_path'] ?? null)
            ? $context->locator['artifact_path']
            : $context->storagePath;

        self::assertOrganizationPath($path, $context->organizationId);

        $stream = $this->files->disk()->readStream($path);

        if (! is_resource($stream)) {
            throw new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable');
        }

        return $stream;
    }

    public static function assertOrganizationPath(string $path, int $organizationId): void
    {
        if (! str_starts_with($path, 'org-'.$organizationId.'/')) {
            throw new TypedFailureException(FailureCategory::Terminal, 'document_storage_scope_invalid');
        }
    }
}
