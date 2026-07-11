<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Support\Collection;

final readonly class UploadDocumentsResult
{
    /** @param Collection<int, \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument> $documents @param array<string, mixed> $summary */
    public function __construct(public Collection $documents, public array $summary) {}
}
