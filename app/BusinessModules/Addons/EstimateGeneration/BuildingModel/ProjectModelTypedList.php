<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @template T of object @implements IteratorAggregate<int, T> */
abstract class ProjectModelTypedList implements Countable, IteratorAggregate
{
    /** @var list<T> */
    private array $items;

    /** @param list<T> $items */
    final protected function __construct(array $items, private readonly string $itemClass)
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('Project model collection must be a list.');
        }
        foreach ($items as $item) {
            if (! $item instanceof $itemClass) {
                throw new InvalidArgumentException('Project model collection item is invalid.');
            }
        }
        $this->items = $items;
    }

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
