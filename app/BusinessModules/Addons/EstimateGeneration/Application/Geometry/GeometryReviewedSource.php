<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

use InvalidArgumentException;

final readonly class GeometryReviewedSource
{
    public function __construct(
        public int $documentId,
        public int $pageId,
        public string $sourceVersion,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['document_id', 'page_id', 'source_version']
            || ! is_int($data['document_id']) || $data['document_id'] < 1
            || ! is_int($data['page_id']) || $data['page_id'] < 1
            || ! is_string($data['source_version'])
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $data['source_version']) !== 1) {
            throw new InvalidArgumentException('Geometry reviewed source is invalid.');
        }

        return new self($data['document_id'], $data['page_id'], $data['source_version']);
    }
}
