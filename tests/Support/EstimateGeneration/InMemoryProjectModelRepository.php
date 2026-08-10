<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use InvalidArgumentException;

final class InMemoryProjectModelRepository implements ProjectModelRepository
{
    public ?int $lastSnapshotFactLimit = null;

    public array $entities = [];

    public array $facts = [];

    public array $evidence = [];

    public array $conflicts = [];

    public array $decisions = [];

    public array $quantities = [];

    public array $links = [];

    public array $understanding = [];

    private array $projection = [];

    public function saveSourceModel(array $entities, array $facts, array $evidence, array $conflicts = []): void
    {
        $modelRecords = [...$entities, ...$facts, ...$conflicts];
        $scopeRecord = $modelRecords[0] ?? null;
        foreach ($modelRecords as $record) {
            if (! is_object($record) || $scopeRecord === null
                || $this->scope($record) !== $this->scope($scopeRecord)
                || $record->sourceVersion !== $scopeRecord->sourceVersion) {
                throw new InvalidArgumentException('Cross-scope source model.');
            }
        }
        $availableEvidence = array_fill_keys(array_keys($this->evidence), true);
        foreach ($evidence as $item) {
            if ($item instanceof Evidence) {
                if ($scopeRecord !== null && $this->scope($item) !== $this->scope($scopeRecord)) {
                    throw new InvalidArgumentException('Cross-scope evidence.');
                }
                $availableEvidence[$this->recordKey($item)] = true;
            }
        }
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Fact expected.');
            }
            foreach ($fact->evidenceIds as $evidenceId) {
                $key = implode(':', [...$this->scope($fact), $fact->sourceVersion, $evidenceId]);
                $sourceAgnostic = array_filter(
                    array_keys($availableEvidence),
                    static fn (string $item): bool => str_starts_with($item, implode(':', [$fact->organizationId, $fact->projectId, $fact->sessionId]).':')
                        && str_ends_with($item, ':'.$evidenceId),
                );
                if (! isset($availableEvidence[$key]) && $sourceAgnostic === []) {
                    throw new InvalidArgumentException('Fact evidence is outside the atomic source model.');
                }
            }
        }
        foreach ($entities as $entity) {
            if (! $entity instanceof Entity) {
                throw new InvalidArgumentException('Entity expected.');
            }
            $this->entities[$this->recordKey($entity)] = $entity;
        }
        foreach ($evidence as $item) {
            if (! $item instanceof Evidence) {
                throw new InvalidArgumentException('Evidence expected.');
            }
            $this->evidence[$this->recordKey($item)] = $item;
        }
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Fact expected.');
            }
            $this->facts[$this->recordKey($fact)] = $fact;
            if (in_array($fact->status, ['confirmed', 'conflicted', 'unresolved'], true)) {
                $this->projection[$this->logicalKey($fact)] = $fact->id;
            }
        }
        foreach ($conflicts as $conflict) {
            if (! $conflict instanceof Conflict) {
                throw new InvalidArgumentException('Conflict expected.');
            }
            $this->conflicts[$this->recordKey($conflict)] = $conflict;
        }
    }

    public function applyDecision(Decision $decision, Fact $selectedFact): void
    {
        $this->assertSameScope($decision, $selectedFact);
        $this->saveSourceModel([], [$selectedFact], [], []);
        $this->decisions[$this->recordKey($decision)] = $decision;
        $prefix = implode(':', $this->scope($decision)).':';
        $this->links = array_filter($this->links, static fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
        $this->understanding = array_filter($this->understanding, static fn (string $key): bool => ! str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);
    }

    public function appendDerivedQuantities(array $quantities, int $chunkSize = 200): void
    {
        foreach ($quantities as $quantity) {
            if (! $quantity instanceof DerivedQuantity) {
                throw new InvalidArgumentException('Derived quantity expected.');
            }
            foreach ($quantity->operands as $operand) {
                $fact = $this->factById($quantity, $operand['fact_id']);
                $projected = $this->projection[$this->logicalKey($fact)] ?? null;
                if ($projected !== $fact->id || $fact->version !== $operand['projection_version']
                    || $fact->status !== $operand['status']) {
                    throw new InvalidArgumentException('Derived quantity operand is not the current projection.');
                }
            }
            $this->quantities[$this->recordKey($quantity)] = $quantity;
        }
    }

    public function snapshot(int $organizationId, int $projectId, int $sessionId, ?int $factLimit = null): ProjectModelSnapshot
    {
        $this->lastSnapshotFactLimit = $factLimit;
        $scope = [$organizationId, $projectId, $sessionId];
        $entities = array_values(array_filter($this->entities, fn (Entity $item): bool => $this->scope($item) === $scope));
        $facts = $this->currentFacts($organizationId, $projectId, $sessionId, limit: $factLimit);
        $evidence = array_values(array_filter($this->evidence, fn (Evidence $item): bool => $this->scope($item) === $scope));
        $conflicts = $this->currentConflicts($organizationId, $projectId, $sessionId);

        return new ProjectModelSnapshot($entities, $facts, $evidence, $conflicts);
    }

    public function currentFacts(
        int $organizationId,
        int $projectId,
        int $sessionId,
        ?string $entityId = null,
        ?int $limit = null,
    ): array {
        $result = [];
        foreach ($this->facts as $fact) {
            if ($this->scope($fact) !== [$organizationId, $projectId, $sessionId]
                || ($entityId !== null && $fact->entityId !== $entityId)
                || ($this->projection[$this->logicalKey($fact)] ?? null) !== $fact->id) {
                continue;
            }
            $result[] = $fact;
        }
        usort($result, static fn (Fact $left, Fact $right): int => [$left->entityId, $left->type] <=> [$right->entityId, $right->type]);

        return $limit === null ? $result : array_slice($result, 0, $limit);
    }

    public function fact(int $organizationId, int $projectId, int $sessionId, string $factId): ?Fact
    {
        foreach ($this->facts as $fact) {
            if ($this->scope($fact) === [$organizationId, $projectId, $sessionId] && $fact->id === $factId) {
                return $fact;
            }
        }

        return null;
    }

    public function currentConflicts(int $organizationId, int $projectId, int $sessionId): array
    {
        return array_values(array_filter(
            $this->conflicts,
            fn (Conflict $item): bool => $this->scope($item) === [$organizationId, $projectId, $sessionId]
                && $item->status === 'unresolved',
        ));
    }

    public function replaceUnderstanding(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        array $links,
        array $conflicts,
        array $questions,
        array $limitations,
        int $providerCalls,
    ): void {
        $key = implode(':', [$organizationId, $projectId, $sessionId, $sourceVersion]);
        $this->links[$key] = $links;
        foreach ($conflicts as $conflict) {
            $this->conflicts[$this->recordKey($conflict)] = $conflict;
        }
        $this->understanding[$key] = [
            'source_version' => $sourceVersion,
            'links' => $links,
            'conflicts' => $conflicts,
            'questions' => $questions,
            'limitations' => $limitations,
            'provider_calls' => $providerCalls,
        ];
    }

    public function currentUnderstanding(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $prefix = implode(':', [$organizationId, $projectId, $sessionId]).':';
        $matches = array_filter($this->understanding, static fn (string $key): bool => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY);

        return $matches === [] ? null : end($matches);
    }

    public function invalidateSourceVersion(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $sourceVersion,
        string $replacementSourceVersion,
    ): void {
        foreach ($this->facts as $fact) {
            $usesReplacedEvidence = false;
            foreach ($fact->evidenceIds as $evidenceId) {
                foreach ($this->evidence as $item) {
                    if ($item->id === $evidenceId && $item->sourceVersion === $sourceVersion
                        && $this->scope($item) === [$organizationId, $projectId, $sessionId]) {
                        $usesReplacedEvidence = true;
                        break 2;
                    }
                }
            }
            if ($this->scope($fact) === [$organizationId, $projectId, $sessionId]
                && ($fact->sourceVersion === $sourceVersion || $usesReplacedEvidence)) {
                unset($this->projection[$this->logicalKey($fact)]);
            }
        }
        foreach (array_keys($this->links) as $key) {
            if (str_starts_with($key, implode(':', [$organizationId, $projectId, $sessionId]).':')) {
                unset($this->links[$key]);
            }
        }
        foreach (array_keys($this->understanding) as $key) {
            if (str_starts_with($key, implode(':', [$organizationId, $projectId, $sessionId]).':')) {
                unset($this->understanding[$key]);
            }
        }
    }

    public function removeProjection(string $factId): void
    {
        foreach ($this->projection as $key => $projectedId) {
            if ($projectedId === $factId) {
                unset($this->projection[$key]);
            }
        }
    }

    private function factById(object $scope, string $factId): Fact
    {
        foreach ($this->facts as $fact) {
            if ($fact->id === $factId && $this->scope($fact) === $this->scope($scope)) {
                return $fact;
            }
        }

        throw new InvalidArgumentException('Fact is outside the requested scope.');
    }

    private function assertSameScope(object $left, object $right): void
    {
        if ($this->scope($left) !== $this->scope($right) || $left->sourceVersion !== $right->sourceVersion) {
            throw new InvalidArgumentException('Records are outside the requested scope.');
        }
    }

    private function recordKey(object $record): string
    {
        return implode(':', [...$this->scope($record), $record->sourceVersion, $record->id]);
    }

    private function logicalKey(Fact $fact): string
    {
        return implode(':', [...$this->scope($fact), $fact->entityId, $fact->type]);
    }

    private function scope(object $record): array
    {
        return [$record->organizationId, $record->projectId, $record->sessionId];
    }
}
