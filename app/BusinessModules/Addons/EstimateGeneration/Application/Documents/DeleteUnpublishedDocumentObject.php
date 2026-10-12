<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\Services\Storage\FileService;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\DB;

/** Both immediate compensation and scheduled recovery use this fail-closed boundary. */
final readonly class DeleteUnpublishedDocumentObject
{
    public function __construct(private FileService $files, private PersistedDocumentObjectReferences $references,
        private DocumentObjectPublicationLock $publication) {}

    public function handle(UnpublishedDocumentObject $object): string
    {
        return DB::transaction(function () use ($object): string {
            $this->publication->acquire($object->path);
            if (! DB::table('estimate_generation_sessions')->where('id', $object->sessionId)
                ->where('organization_id', $object->organizationId)->where('project_id', $object->projectId)->exists()) {
                throw new \InvalidArgumentException('unpublished_document_scope_invalid');
            }
            if ($this->references->contains($object->path)) {
                return 'blocked_reference';
            }
            try {
                $head = $this->files->describeHead($object->path);
            } catch (AwsException $error) {
                if ($error->getStatusCode() === 404) {
                    return 'completed';
                }
                throw $error;
            }
            if ($head['size'] !== $object->sizeBytes) {
                return 'blocked_identity';
            }
            $current = $this->files->describeCurrent($object->path, -$object->sizeBytes);
            if ($current['size'] !== $object->sizeBytes || ! hash_equals($object->sha256, $current['sha256'])) {
                return 'blocked_identity';
            }
            if ($this->references->contains($object->path)) {
                return 'blocked_reference';
            }
            // The shared lock protects participating publishers. This deletes the current key, not a pinned S3 version.
            $this->files->removeImmutable($object->path);

            return 'completed';
        }, 1);
    }
}
