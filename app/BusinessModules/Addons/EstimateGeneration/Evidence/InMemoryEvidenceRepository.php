<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use DateTimeImmutable;

final class InMemoryEvidenceRepository implements EvidenceRepository
{
    /** @var array<int, EvidenceNode> */
    private array $nodes = [];

    /** @var array<string, int> */
    private array $fingerprints = [];

    /** @var array<string, EvidenceEdge> */
    private array $edges = [];

    private int $nextId = 1;

    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
    {
        return $callback();
    }

    public function insertOrGet(EvidenceData $data): EvidenceNode
    {
        $fingerprint = $data->fingerprint();
        if (isset($this->fingerprints[$fingerprint])) {
            return $this->nodes[$this->fingerprints[$fingerprint]];
        }
        $node = new EvidenceNode($this->nextId++, $data, $fingerprint);
        $this->nodes[$node->id] = $node;
        $this->fingerprints[$fingerprint] = $node->id;

        return $node;
    }

    public function node(int $organizationId, int $projectId, int $sessionId, int $id): ?EvidenceNode
    {
        $node = $this->nodes[$id] ?? null;

        return $node !== null
            && $node->organizationId === $organizationId
            && $node->projectId === $projectId
            && $node->sessionId === $sessionId ? $node : null;
    }

    public function activeNodesForUpdate(int $organizationId, int $projectId, int $sessionId, array $ids): array
    {
        $nodes = [];
        foreach (array_unique($ids) as $id) {
            $node = $this->node($organizationId, $projectId, $sessionId, $id);
            if ($node !== null && $node->invalidatedAt === null) {
                $nodes[] = $node;
            }
        }
        usort($nodes, static fn (EvidenceNode $left, EvidenceNode $right): int => $left->id <=> $right->id);

        return $nodes;
    }

    public function addEdge(EvidenceEdge $edge): void
    {
        $key = implode(':', [$edge->organizationId, $edge->sessionId, $edge->parentId, $edge->childId, $edge->relation->value]);
        $this->edges[$key] = $edge;
    }

    public function pathExists(int $organizationId, int $projectId, int $sessionId, int $fromId, int $toId): bool
    {
        $queue = [$fromId];
        $visited = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $toId) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($this->edges as $edge) {
                if ($edge->organizationId === $organizationId && $edge->projectId === $projectId
                    && $edge->sessionId === $sessionId && $edge->parentId === $current) {
                    $queue[] = $edge->childId;
                }
            }
        }

        return false;
    }

    public function descendantBatches(int $organizationId, int $projectId, int $sessionId, array $types, string $ref, string $version, int $chunkSize): iterable
    {
        $queue = array_values(array_map(
            static fn (EvidenceNode $node): int => $node->id,
            array_filter($this->nodes, static fn (EvidenceNode $node): bool => $node->organizationId === $organizationId && $node->projectId === $projectId
                && $node->sessionId === $sessionId && in_array($node->sourceType, $types, true)
                && $node->sourceRef === $ref && $node->sourceVersion === $version),
        ));
        $visited = [];
        $batch = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $batch[] = $id;
            foreach ($this->edges as $edge) {
                if ($edge->organizationId === $organizationId && $edge->projectId === $projectId
                    && $edge->sessionId === $sessionId && $edge->parentId === $id) {
                    $queue[] = $edge->childId;
                }
            }
            if (count($batch) === $chunkSize) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    public function invalidate(int $organizationId, int $projectId, int $sessionId, array $ids, string $reason): int
    {
        $count = 0;
        $at = new DateTimeImmutable;
        foreach (array_unique($ids) as $id) {
            $node = $this->node($organizationId, $projectId, $sessionId, $id);
            if ($node !== null && $node->invalidatedAt === null) {
                $this->nodes[$id] = $node->invalidate($at, $reason);
                $count++;
            }
        }

        return $count;
    }

    /** @return list<EvidenceNode> */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return list<EvidenceEdge> */
    public function edges(): array
    {
        return array_values($this->edges);
    }
}
