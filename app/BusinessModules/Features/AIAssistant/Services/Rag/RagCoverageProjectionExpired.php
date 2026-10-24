<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use RuntimeException;

final class RagCoverageProjectionExpired extends RuntimeException
{
    private function __construct(public readonly string $abortSite)
    {
        parent::__construct('rag_coverage_projection_expired');
    }

    public static function refreshGuard(): self
    {
        return new self('refresh_guard');
    }

    public static function publicationRevision(): self
    {
        return new self('publish_revision_mismatch');
    }
}
