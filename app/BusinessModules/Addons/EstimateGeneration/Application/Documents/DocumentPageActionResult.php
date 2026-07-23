<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use Illuminate\Support\Collection;

final readonly class DocumentPageActionResult
{
    /**
     * @param Collection<int, mixed> $pages
     * @param array<string, mixed> $summary
     */
    public function __construct(
        public EstimateGenerationDocument $document,
        public Collection $pages,
        public array $summary,
        public string $messageKey,
        public array $pageSummary,
    ) {}
}
