<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class UnpublishedDocumentObject
{
    public const MAX_BYTES = 209715200;

    public function __construct(public int $organizationId, public int $projectId, public int $sessionId,
        public string $path, public string $sha256, public int $sizeBytes)
    {
        $prefix = 'org-'.$organizationId.'/estimate-generation/sessions/'.$sessionId.'/documents/';
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1 || ! str_starts_with($path, $prefix)
            || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.[a-zA-Z0-9]{1,12}\z/', substr($path, strlen($prefix))) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1 || $sizeBytes < 1 || $sizeBytes > self::MAX_BYTES) {
            throw new InvalidArgumentException('unpublished_document_object_invalid');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [$this->organizationId, $this->projectId, $this->sessionId, $this->path, $this->sha256, $this->sizeBytes]));
    }
}
