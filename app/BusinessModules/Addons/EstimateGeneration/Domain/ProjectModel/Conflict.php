<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class Conflict
{
    public array $facts;

    public array $evidenceIds;

    private function __construct(
        public string $id,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        array $facts,
        public string $reason,
        public string $status = 'unresolved',
        public int $version = 1,
        bool $acrossSources = false,
    ) {
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $sourceVersion);
        ProjectModelInvariant::id($id, 'Conflict');
        if (count($facts) < 2 || trim($reason) === '' || ! in_array($status, ['unresolved', 'resolved'], true)
            || $version <= 0) {
            throw new InvalidArgumentException('Project model conflict is invalid.');
        }
        $values = [];
        $ids = [];
        $evidenceIds = [];
        foreach ($facts as $fact) {
            if (! $fact instanceof Fact) {
                throw new InvalidArgumentException('Project model conflict fact is invalid.');
            }
            if ($acrossSources) {
                if ([$this->organizationId, $this->projectId, $this->sessionId]
                    !== [$fact->organizationId, $fact->projectId, $fact->sessionId]) {
                    throw new InvalidArgumentException('Project model conflict fact is outside the requested scope.');
                }
            } else {
                ProjectModelInvariant::sameScope($this, $fact);
            }
            if (isset($ids[$fact->id])) {
                throw new InvalidArgumentException('Project model conflict fact is duplicated.');
            }
            $ids[$fact->id] = true;
            $values[self::fingerprint($fact->value, $fact->unit)] = true;
            $evidenceIds = [...$evidenceIds, ...$fact->evidenceIds];
        }
        if (count($values) < 2) {
            throw new InvalidArgumentException('Project model conflict facts are compatible.');
        }
        usort($facts, static fn (Fact $left, Fact $right): int => $left->id <=> $right->id);
        $this->facts = $facts;
        $this->evidenceIds = ProjectModelInvariant::uniqueIds($evidenceIds, 'Conflict evidence');
    }

    public static function between(string $id, array $facts, string $reason): self
    {
        $first = $facts[0] ?? null;
        if (! $first instanceof Fact) {
            throw new InvalidArgumentException('Project model conflict has no facts.');
        }

        return new self(
            $id,
            $first->organizationId,
            $first->projectId,
            $first->sessionId,
            $first->sourceVersion,
            $facts,
            $reason,
        );
    }

    public static function betweenSources(string $id, array $facts, string $reason, string $runSourceVersion): self
    {
        $first = $facts[0] ?? null;
        if (! $first instanceof Fact) {
            throw new InvalidArgumentException('Project model conflict has no facts.');
        }

        return new self($id, $first->organizationId, $first->projectId, $first->sessionId,
            $runSourceVersion, $facts, $reason, acrossSources: true);
    }

    private static function fingerprint(mixed $value, ?string $unit): string
    {
        return hash('sha256', json_encode([$value, $unit], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
