<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelMergeResult
{
    public function __construct(
        public array $resolved,
        public array $conflicts,
        public array $unconfirmed,
    ) {
        self::assertCollection($resolved, ProjectModelResolvedValue::class, 'Resolved values');
        self::assertCollection($conflicts, ProjectModelConflict::class, 'Conflicts');
        self::assertCollection($unconfirmed, ProjectModelConflict::class, 'Unconfirmed values');
        foreach ($conflicts as $conflict) {
            if (! str_ends_with($conflict->code, '_conflict')) {
                throw new InvalidArgumentException('Conflict result contains an unconfirmed value.');
            }
        }
        foreach ($unconfirmed as $conflict) {
            if (! str_ends_with($conflict->code, '_unconfirmed')) {
                throw new InvalidArgumentException('Unconfirmed result contains a conflict.');
            }
        }
    }

    private static function assertCollection(array $values, string $class, string $subject): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException("{$subject} must be a list.");
        }
        foreach ($values as $value) {
            if (! $value instanceof $class) {
                throw new InvalidArgumentException("{$subject} contains an invalid item.");
            }
        }
    }
}
