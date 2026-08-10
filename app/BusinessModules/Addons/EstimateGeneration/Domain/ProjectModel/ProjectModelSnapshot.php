<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class ProjectModelSnapshot
{
    public function __construct(
        public array $entities,
        public array $facts,
        public array $evidence,
        public array $conflicts,
    ) {
        $this->assertInstances($entities, Entity::class);
        $this->assertInstances($facts, Fact::class);
        $this->assertInstances($evidence, Evidence::class);
        $this->assertInstances($conflicts, Conflict::class);
        $scope = $facts[0] ?? $entities[0] ?? $evidence[0] ?? $conflicts[0] ?? null;
        if ($scope === null) {
            return;
        }
        foreach ([...$entities, ...$facts, ...$evidence, ...$conflicts] as $record) {
            if ($record->organizationId !== $scope->organizationId
                || $record->projectId !== $scope->projectId
                || $record->sessionId !== $scope->sessionId) {
                throw new InvalidArgumentException('Project model snapshot contains a cross-scope record.');
            }
        }
    }

    private function assertInstances(array $records, string $class): void
    {
        foreach ($records as $record) {
            if (! $record instanceof $class) {
                throw new InvalidArgumentException('Project model snapshot record is invalid.');
            }
        }
    }
}
