<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final readonly class EvidenceInvalidator
{
    public function __construct(private EvidenceRepository $repository, private int $chunkSize = 250)
    {
        if ($chunkSize < 1 || $chunkSize > 1000) {
            throw new InvalidArgumentException('Evidence traversal chunk size is invalid.');
        }
    }

    public function invalidateSource(
        int $organizationId,
        int $projectId,
        int $sessionId,
        EvidenceSourceType $sourceType,
        string $sourceRef,
        string $sourceVersion,
        string $reason,
    ): int {
        return $this->invalidateSources(
            $organizationId, $projectId, $sessionId, [$sourceType], $sourceRef, $sourceVersion, $reason,
        );
    }

    /** @param non-empty-list<EvidenceSourceType> $sourceTypes */
    public function invalidateSources(
        int $organizationId,
        int $projectId,
        int $sessionId,
        array $sourceTypes,
        string $sourceRef,
        string $sourceVersion,
        string $reason,
    ): int {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1 || $sourceTypes === []
            || $sourceRef === '' || strlen($sourceRef) > 160 || $sourceVersion === '' || strlen($sourceVersion) > 80
            || $reason === '' || strlen($reason) > 80) {
            throw new InvalidArgumentException('Evidence invalidation scope is invalid.');
        }
        foreach ($sourceTypes as $sourceType) {
            if (! $sourceType instanceof EvidenceSourceType) {
                throw new InvalidArgumentException('Evidence source type is invalid.');
            }
        }

        return $this->repository->transaction($organizationId, $sessionId, function () use (
            $organizationId, $projectId, $sessionId, $sourceTypes, $sourceRef, $sourceVersion, $reason,
        ): int {
            $invalidated = 0;
            foreach ($this->repository->descendantBatches(
                $organizationId, $projectId, $sessionId, $sourceTypes, $sourceRef, $sourceVersion, $this->chunkSize,
            ) as $batch) {
                $invalidated += $this->repository->invalidate(
                    $organizationId, $projectId, $sessionId, $batch, $reason,
                );
            }

            return $invalidated;
        });
    }
}
