<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentRepresentationResourceLimits
{
    public function __construct(
        private int $maxPages = 200,
        private int $maxObjects = 250_000,
        private int $maxBytes = 104_857_600,
        private int $maxPeakMemoryBytes = 536_870_912,
        private int $maxDurationMs = 60_000,
    ) {}

    public function assertWithin(array $usage): void
    {
        $required = ['pages', 'objects', 'bytes', 'peak_memory_bytes', 'duration_ms'];
        if (array_keys($usage) !== $required) {
            throw new InvalidArgumentException('Document representation resource usage is invalid.');
        }
        foreach ($usage as $value) {
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Document representation resource usage is invalid.');
            }
        }

        $limits = [
            'pages' => [$this->maxPages, 'document_representation_page_limit_exceeded'],
            'objects' => [$this->maxObjects, 'document_representation_object_limit_exceeded'],
            'bytes' => [$this->maxBytes, 'document_representation_size_limit_exceeded'],
            'peak_memory_bytes' => [$this->maxPeakMemoryBytes, 'document_representation_memory_limit_exceeded'],
            'duration_ms' => [$this->maxDurationMs, 'document_representation_timeout_exceeded'],
        ];
        foreach ($limits as $metric => [$limit, $safeCode]) {
            if ($usage[$metric] > $limit) {
                throw new DocumentManifestNeedsReview($safeCode, [
                    'metric' => $metric,
                    'actual' => $usage[$metric],
                    'limit' => $limit,
                ]);
            }
        }
    }
}
