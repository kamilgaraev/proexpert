<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

/** Memory survives a nested database rollback; it contains no file bodies or source-copy paths. */
final class UnpublishedDocumentObjectBatch
{
    /** @var array<string,UnpublishedDocumentObject> */
    private array $objects = [];

    public function __construct(private readonly int $organizationId, private readonly int $projectId, private readonly int $sessionId) {}

    public function capture(string $path, string $sha256, int $sizeBytes): void
    {
        $object = new UnpublishedDocumentObject($this->organizationId, $this->projectId, $this->sessionId, $path, $sha256, $sizeBytes);
        if (isset($this->objects[$path]) && ! hash_equals($this->objects[$path]->fingerprint(), $object->fingerprint())) {
            throw new InvalidArgumentException('unpublished_document_object_identity_changed');
        }
        $this->objects[$path] = $object;
    }

    /** @return list<UnpublishedDocumentObject> */
    public function objects(): array
    {
        return array_values($this->objects);
    }
}
