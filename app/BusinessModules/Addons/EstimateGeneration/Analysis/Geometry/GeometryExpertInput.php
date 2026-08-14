<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Geometry;

use InvalidArgumentException;

final readonly class GeometryExpertInput
{
    /** @param list<array<string,mixed>> $sheets */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public array $sheets,
    ) {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1
            || count($sheets) > 10_000 || ! array_is_list($sheets)) {
            throw new InvalidArgumentException('geometry_expert_input_invalid');
        }
    }

    /** @return list<array<string,mixed>> */
    public function fingerprintSheets(): array
    {
        return array_map(static function (mixed $sheet): array {
            if (! is_array($sheet)) {
                return ['invalid' => true];
            }
            $source = $sheet['source'] ?? null;
            if ($source instanceof \App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput) {
                $sheet['source'] = [
                    'document_id' => $source->documentId,
                    'page_id' => $source->pageId,
                    'page_number' => $source->pageNumber,
                    'processing_unit_id' => $source->processingUnitId,
                    'source_version' => $source->sourceVersion,
                    'derivative_hash' => $source->derivativeHash,
                ];
            }

            return $sheet;
        }, $this->sheets);
    }
}
