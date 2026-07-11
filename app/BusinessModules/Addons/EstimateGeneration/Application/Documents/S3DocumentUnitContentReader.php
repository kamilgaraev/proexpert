<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\Services\Storage\FileService;
use RuntimeException;

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
            throw new RuntimeException('estimate_generation.document_unit_stream_unavailable');
        }

        return $stream;
    }

    public static function assertOrganizationPath(string $path, int $organizationId): void
    {
        if (! str_starts_with($path, 'org-'.$organizationId.'/')) {
            throw new RuntimeException('estimate_generation.document_unit_scope_invalid');
        }
    }
}
