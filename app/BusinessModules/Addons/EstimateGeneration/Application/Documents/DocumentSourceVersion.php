<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use RuntimeException;

final readonly class DocumentSourceVersion
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) !== 1) {
            throw new RuntimeException('estimate_generation.document_source_version_unavailable');
        }

        return new self($value);
    }

    public static function fromDocument(EstimateGenerationDocument $document): string
    {
        $checksum = strtolower(trim((string) $document->checksum_sha256));

        if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
            throw new RuntimeException('estimate_generation.document_source_version_unavailable');
        }

        return self::fromString('sha256:'.$checksum)->value;
    }
}
