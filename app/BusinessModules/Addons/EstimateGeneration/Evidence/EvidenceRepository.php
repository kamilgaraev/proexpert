<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

interface EvidenceRepository
{
    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed;

    public function insertOrGet(EvidenceData $data): EvidenceNode;

    public function node(int $organizationId, int $projectId, int $sessionId, int $id): ?EvidenceNode;

    public function addEdge(EvidenceEdge $edge): void;

    public function pathExists(int $organizationId, int $projectId, int $sessionId, int $fromId, int $toId): bool;

    /** @return list<int> */
    public function sourceRoots(int $organizationId, int $projectId, int $sessionId, EvidenceSourceType $type, string $ref, string $version): array;

    /** @param list<int> $parentIds @return list<int> */
    public function children(int $organizationId, int $projectId, int $sessionId, array $parentIds): array;

    /** @param list<int> $ids */
    public function invalidate(int $organizationId, int $projectId, int $sessionId, array $ids, string $reason): int;
}
