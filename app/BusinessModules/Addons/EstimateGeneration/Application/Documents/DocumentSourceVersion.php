<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use RuntimeException;

final class DocumentSourceVersion
{
    public static function fromDocument(EstimateGenerationDocument $document): string
    {
        $checksum = strtolower(trim((string) $document->checksum_sha256));

        if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new RuntimeException('estimate_generation.document_source_version_unavailable');
        }

        return 'sha256:'.$checksum;
    }
}
